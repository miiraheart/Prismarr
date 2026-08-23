<?php

namespace App\Controller;

use App\Entity\Media\WatchlistItem;
use App\Repository\Media\WatchlistItemRepository;
use App\Service\Media\JellyseerrClient;
use App\Service\Media\TmdbClient;
use App\Service\Media\TraktClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Trakt watchlist, rendered from the read-through cache in TraktClient.
 *
 * No entities and no migrations: the whole dataset is a few hundred rows per
 * endpoint on a single-user dashboard, and TraktClient already caches it for
 * 15 minutes.
 *
 * The page renders from the watchlist call alone so it paints fast. Posters
 * (TMDb, one call per title, cached 6h) and the rating / watched marks (two
 * more Trakt collections, the largest of which pages seven times) are fetched
 * afterwards by the browser, so a cold cache never blocks the first render.
 */
#[IsGranted('ROLE_USER')]
#[Route('/trakt', name: 'trakt_')]
class TraktController extends AbstractController
{
    public function __construct(
        private readonly TraktClient              $trakt,
        private readonly TmdbClient               $tmdb,
        private readonly JellyseerrClient         $jellyseerr,
        private readonly WatchlistItemRepository  $watchlistRepo,
        private readonly EntityManagerInterface   $em,
        private readonly LoggerInterface          $logger,
        private readonly TranslatorInterface      $translator,
    ) {}

    #[Route('', name: 'index')]
    public function index(): Response
    {
        $error = false;
        $items = [];

        try {
            $items = $this->trakt->getWatchlist();
        } catch (\Throwable $e) {
            $this->logger->warning('Trakt watchlist failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }

        $local = $this->localWatchlistIndex();

        $rows = [];
        foreach ($items as $item) {
            $rows[] = $item + ['in_local' => isset($local[$item['type'] . ':' . $item['tmdb_id']])];
        }

        // Newest listed first, which is the order the Trakt site itself shows.
        usort($rows, static fn (array $a, array $b): int => ($b['listed_at'] ?? '') <=> ($a['listed_at'] ?? ''));

        return $this->render('trakt/index.html.twig', [
            'items'        => $rows,
            'error'        => $error,
            'can_write'    => $this->trakt->hasWriteAccess(),
            'can_connect'  => $this->trakt->canStartDeviceAuth(),
            'movies'       => count(array_filter($rows, static fn (array $r): bool => $r['type'] === 'movie')),
            'shows'        => count(array_filter($rows, static fn (array $r): bool => $r['type'] === 'tv')),
        ]);
    }

    /**
     * Poster and score for one title, so the grid can hydrate itself without
     * paying 57 sequential TMDb calls during the page render.
     */
    #[Route('/meta/{type}/{tmdbId}', name: 'meta', requirements: ['type' => 'movie|tv', 'tmdbId' => '\d+'], methods: ['GET'])]
    public function meta(string $type, int $tmdbId): JsonResponse
    {
        try {
            $detail = $type === 'movie' ? $this->tmdb->getMovie($tmdbId) : $this->tmdb->getTv($tmdbId);
        } catch (\Throwable $e) {
            $this->logger->warning('Trakt meta lookup failed', ['tmdb_id' => $tmdbId, 'message' => $e->getMessage()]);
            $detail = null;
        }

        if ($detail === null) {
            return $this->json(['poster' => null, 'vote' => null]);
        }

        return $this->json([
            'poster' => TmdbClient::posterUrl($detail['poster_path'] ?? null, 'w342'),
            'vote'   => isset($detail['vote_average']) ? round((float) $detail['vote_average'], 1) : null,
        ]);
    }

    /**
     * Personal ratings and watched state, keyed "{type}:{tmdb_id}". Fetched
     * separately from the page because /watched is the largest collection on
     * the account and a cold cache would otherwise stall the first paint.
     */
    #[Route('/marks', name: 'marks', methods: ['GET'])]
    public function marks(): JsonResponse
    {
        try {
            return $this->json([
                'ratings' => $this->trakt->getRatings(),
                'watched' => $this->trakt->getWatched(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Trakt marks failed', ['exception' => $e::class, 'message' => $e->getMessage()]);

            return $this->json(['ratings' => [], 'watched' => []]);
        }
    }

    /**
     * Titles currently mid-watch, keyed "{type}:{tmdb_id}", so a media card
     * anywhere in the app (Decouverte, Lists, this page) can stamp a
     * "watching" badge on it. Cached 5 minutes in TraktClient, shorter than
     * the other reads because progress changes while Mira is watching.
     */
    #[Route('/playback', name: 'playback', methods: ['GET'])]
    public function playback(): JsonResponse
    {
        try {
            return $this->json($this->trakt->getPlayback());
        } catch (\Throwable $e) {
            $this->logger->warning('Trakt playback failed', ['exception' => $e::class, 'message' => $e->getMessage()]);

            return $this->json([]);
        }
    }

    // No CSRF token: internal app, routes protected by the class-level IsGranted.
    #[Route('/request', name: 'request', methods: ['POST'])]
    public function createRequest(Request $request): JsonResponse
    {
        [$tmdbId, $type] = $this->readTarget($request);
        if ($tmdbId === 0) {
            return $this->json(['error' => $this->translator->trans('trakt.error.invalid_params')], 400);
        }

        $created = $this->jellyseerr->createRequest($tmdbId, $type);
        if ($created === null) {
            $upstream = $this->jellyseerr->getLastError();

            return $this->json([
                'error' => $upstream['message'] ?? $this->translator->trans('trakt.error.request_failed'),
            ], 502);
        }

        return $this->json(['ok' => true, 'status' => $created['status'] ?? null]);
    }

    // No CSRF token: internal app, routes protected by the class-level IsGranted.
    #[Route('/watchlist', name: 'watchlist_add', methods: ['POST'])]
    public function watchlistAdd(Request $request): JsonResponse
    {
        [$tmdbId, $type] = $this->readTarget($request);
        if ($tmdbId === 0) {
            return $this->json(['error' => $this->translator->trans('trakt.error.invalid_params')], 400);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        if ($this->addLocal($tmdbId, $type, (string) ($data['title'] ?? ''), isset($data['year']) ? (int) $data['year'] : null)) {
            $this->em->flush();

            return $this->json(['ok' => true, 'action' => 'added']);
        }

        return $this->json(['ok' => true, 'action' => 'exists']);
    }

    /**
     * One-click import of the whole Trakt watchlist into the local one, which
     * is what Mira asked for instead of a second permanent watchlist section.
     * Existing entries are left alone.
     */
    // No CSRF token: internal app, routes protected by the class-level IsGranted.
    #[Route('/import', name: 'import', methods: ['POST'])]
    public function import(): JsonResponse
    {
        try {
            $items = $this->trakt->getWatchlist();
        } catch (\Throwable $e) {
            $this->logger->warning('Trakt import failed', ['exception' => $e::class, 'message' => $e->getMessage()]);

            return $this->json(['error' => $this->translator->trans('trakt.error.unavailable')], 502);
        }

        $added = 0;
        foreach ($items as $item) {
            if ($this->addLocal($item['tmdb_id'], $item['type'], (string) $item['title'], $item['year'])) {
                $added++;
            }
        }
        $this->em->flush();

        return $this->json(['ok' => true, 'added' => $added, 'total' => count($items)]);
    }

    // ── Trakt write access (OAuth device flow) ───────────────────────────────

    /**
     * Start the device flow. Returns the short code the user types at
     * trakt.tv/activate. The device code itself stays server-side.
     */
    // No CSRF token: internal app, routes protected by the class-level IsGranted.
    #[Route('/connect', name: 'connect', methods: ['POST'])]
    public function connect(): JsonResponse
    {
        if (!$this->trakt->canStartDeviceAuth()) {
            return $this->json(['error' => $this->translator->trans('trakt.connect.need_secret')], 400);
        }

        $started = $this->trakt->startDeviceAuth();
        if ($started === null) {
            return $this->json(['error' => $this->translator->trans('trakt.connect.failed')], 502);
        }

        return $this->json($started);
    }

    // No CSRF token: internal app, routes protected by the class-level IsGranted.
    #[Route('/connect/poll', name: 'connect_poll', methods: ['POST'])]
    public function connectPoll(): JsonResponse
    {
        return $this->json(['status' => $this->trakt->pollDeviceAuth()]);
    }

    // No CSRF token: internal app, routes protected by the class-level IsGranted.
    #[Route('/disconnect', name: 'disconnect', methods: ['POST'])]
    public function disconnect(): JsonResponse
    {
        $this->trakt->disconnect();

        return $this->json(['ok' => true]);
    }

    /**
     * Remove a title from the Trakt watchlist itself, not just from this page.
     * Needs the device flow to have been completed.
     */
    // No CSRF token: internal app, routes protected by the class-level IsGranted.
    #[Route('/remove', name: 'remove', methods: ['POST'])]
    public function remove(Request $request): JsonResponse
    {
        [$tmdbId, $type] = $this->readTarget($request);
        if ($tmdbId === 0) {
            return $this->json(['error' => $this->translator->trans('trakt.error.invalid_params')], 400);
        }
        if (!$this->trakt->hasWriteAccess()) {
            return $this->json(['error' => $this->translator->trans('trakt.connect.needed')], 403);
        }

        try {
            $done = $this->trakt->removeFromWatchlist($tmdbId, $type);
        } catch (\Throwable $e) {
            $this->logger->warning('Trakt remove failed', ['tmdb_id' => $tmdbId, 'message' => $e->getMessage()]);
            $done = false;
        }

        return $done
            ? $this->json(['ok' => true])
            : $this->json(['error' => $this->translator->trans('trakt.error.remove_failed')], 502);
    }

    // No CSRF token: internal app, routes protected by the class-level IsGranted.
    #[Route('/watched', name: 'watched', methods: ['POST'])]
    public function markWatched(Request $request): JsonResponse
    {
        return $this->write($request, fn (int $id, string $type): bool => $this->trakt->markWatched($id, $type));
    }

    // No CSRF token: internal app, routes protected by the class-level IsGranted.
    #[Route('/dropped', name: 'dropped', methods: ['POST'])]
    public function markDropped(Request $request): JsonResponse
    {
        return $this->write($request, fn (int $id, string $type): bool => $this->trakt->markDropped($id, $type));
    }

    /**
     * Shared shell for the write actions: validate, require the OAuth link,
     * run the call, never let an exception reach the client.
     *
     * @param callable(int, string):bool $action
     */
    private function write(Request $request, callable $action): JsonResponse
    {
        [$tmdbId, $type] = $this->readTarget($request);
        if ($tmdbId === 0) {
            return $this->json(['error' => $this->translator->trans('trakt.error.invalid_params')], 400);
        }
        if (!$this->trakt->hasWriteAccess()) {
            return $this->json(['error' => $this->translator->trans('trakt.connect.needed')], 403);
        }

        try {
            $done = $action($tmdbId, $type);
        } catch (\Throwable $e) {
            $this->logger->warning('Trakt write failed', ['tmdb_id' => $tmdbId, 'message' => $e->getMessage()]);
            $done = false;
        }

        return $done
            ? $this->json(['ok' => true])
            : $this->json(['error' => $this->translator->trans('trakt.error.write_failed')], 502);
    }

    /**
     * @return array{0:int, 1:string} tmdb id (0 when invalid) and media type
     */
    private function readTarget(Request $request): array
    {
        $data   = json_decode($request->getContent(), true) ?? [];
        $tmdbId = (int) ($data['tmdb_id'] ?? 0);
        $type   = (string) ($data['type'] ?? '');

        if ($tmdbId <= 0 || !in_array($type, ['movie', 'tv'], true)) {
            return [0, ''];
        }

        return [$tmdbId, $type];
    }

    /**
     * Persist one local watchlist row unless it already exists. The caller
     * flushes, so a bulk import is a single transaction.
     *
     * Trakt carries no artwork, and the local watchlist stores its own poster
     * path, so the TMDb detail is pulled here. By the time the import button
     * can be clicked the grid has already hydrated every visible title, so
     * these are cache hits rather than fresh calls.
     */
    private function addLocal(int $tmdbId, string $type, string $title, ?int $year): bool
    {
        if ($this->watchlistRepo->findByTmdb($tmdbId, $type) !== null) {
            return false;
        }

        try {
            $detail = $type === 'movie' ? $this->tmdb->getMovie($tmdbId) : $this->tmdb->getTv($tmdbId);
        } catch (\Throwable) {
            $detail = null;
        }

        $item = new WatchlistItem();
        $item->setTmdbId($tmdbId)
             ->setMediaType($type)
             ->setTitle($title !== '' ? $title : (string) $tmdbId)
             ->setPosterPath($detail['poster_path'] ?? null)
             ->setVote(isset($detail['vote_average']) ? (float) $detail['vote_average'] : null)
             ->setYear($year);

        $this->em->persist($item);

        return true;
    }

    /**
     * @return array<string, true> keyed "{type}:{tmdb_id}"
     */
    private function localWatchlistIndex(): array
    {
        $index = [];
        foreach ($this->watchlistRepo->findAllOrdered() as $row) {
            $index[$row->getMediaType() . ':' . $row->getTmdbId()] = true;
        }

        return $index;
    }
}
