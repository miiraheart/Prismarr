<?php

namespace App\Controller;

use App\Service\ConfigService;
use App\Service\Media\JellyseerrClient;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Adding a title from a browse page goes through Seerr, not straight to
 * Radarr or Sonarr.
 *
 * Mira's rule, 2026-08-24: every Prismarr page that lists items (the Discover,
 * Lists and Watchlists tabs, the dashboard tiles, the detail modal) creates a
 * SEERR REQUEST, so the add is recorded with a requester and a timestamp and
 * Seerr performs the actual Radarr/Sonarr add. The Radarr and Sonarr pages
 * themselves keep adding directly, because routing an add made from inside
 * Radarr's own page out to Seerr just to have Seerr hand it back is illogical.
 *
 * That split needs no list of pages to maintain. The browse pages are exactly
 * the ones whose cards carry `.tmdb-add-btn`, which the Radarr and Sonarr
 * pages never render.
 *
 * Upstream issue Shoshuo/Prismarr#87 asks for the same thing but routes
 * EVERYTHING through Seerr, including the Radarr page. Do not quietly adopt
 * that behaviour if it ever lands upstream.
 */
#[IsGranted('ROLE_USER')]
class MediaRequestController extends AbstractController
{
    /**
     * How many recent requests to index for the card badges. Seerr's own
     * request list is the source; this bounds one call rather than paging the
     * whole history on every page view.
     */
    private const STATE_PAGE = 100;

    public function __construct(
        private readonly JellyseerrClient $jellyseerr,
        private readonly ConfigService    $config,
        private readonly LoggerInterface  $logger,
        private readonly TranslatorInterface $translator,
    ) {}

    /**
     * Create a Seerr request for one title.
     *
     * Answers `fallback: true` rather than an error when Seerr is not
     * configured, so the caller can drop back to the direct Radarr/Sonarr
     * quick-add instead of the Add button going dead for anyone without Seerr.
     * That fallback is called for explicitly in upstream issue #87.
     */
    // No CSRF token: internal app, protected by the class-level IsGranted,
    // matching TraktController's write routes.
    #[Route('/media-request', name: 'media_request_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        if (!$this->seerrConfigured()) {
            return $this->json(['ok' => false, 'fallback' => true]);
        }

        $payload = json_decode($request->getContent(), true) ?? [];
        $tmdbId  = (int) ($payload['tmdb_id'] ?? 0);
        $type    = (string) ($payload['type'] ?? '');

        if ($tmdbId <= 0 || !in_array($type, ['movie', 'tv'], true)) {
            return $this->json(['ok' => false, 'error' => $this->translator->trans('request.error.invalid')], 400);
        }

        try {
            $created = $this->jellyseerr->createRequest($tmdbId, $type);
        } catch (\Throwable $e) {
            $this->logger->warning('Seerr request failed', [
                'tmdb_id'   => $tmdbId,
                'type'      => $type,
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);
            $created = null;
        }

        if ($created === null) {
            $upstream = $this->jellyseerr->getLastError();

            // A title Seerr already knows about is not a failure worth a red
            // toast: it is the normal answer for something already requested.
            $message = (string) ($upstream['message'] ?? '');
            if (stripos($message, 'already exists') !== false || stripos($message, 'already requested') !== false) {
                return $this->json(['ok' => true, 'duplicate' => true]);
            }

            return $this->json([
                'ok'    => false,
                'error' => $message !== '' ? $message : $this->translator->trans('request.error.failed'),
            ], 502);
        }

        return $this->json([
            'ok'     => true,
            'status' => $created['status'] ?? null,
        ]);
    }

    /**
     * Existing Seerr requests, keyed "{type}:{tmdb_id}", so a card can show
     * that a title was already asked for, by whom and when.
     *
     * ONE call, indexed here. Asking Seerr per card would be one request per
     * poster on a grid of sixty, which is the trap the Trakt poster hydration
     * already fell into.
     */
    #[Route('/media-request/states', name: 'media_request_states', methods: ['GET'])]
    public function states(): JsonResponse
    {
        if (!$this->seerrConfigured()) {
            return $this->json([]);
        }

        try {
            $data = $this->jellyseerr->getRequests(self::STATE_PAGE, 0, 'all');
        } catch (\Throwable $e) {
            $this->logger->warning('Seerr request states failed', [
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);

            return $this->json([]);
        }

        $out = [];
        foreach (($data['results'] ?? []) as $req) {
            $tmdbId = $req['media']['tmdbId'] ?? null;
            $type   = $req['media']['mediaType'] ?? $req['type'] ?? null;
            if (!$tmdbId || !in_array($type, ['movie', 'tv'], true)) {
                continue;
            }

            $key = $type . ':' . (int) $tmdbId;

            // Keep the newest request for a title: Seerr allows several, and
            // the latest is the one whose state the card should reflect.
            $createdAt = (string) ($req['createdAt'] ?? '');
            if (isset($out[$key]) && $createdAt < $out[$key]['requested_at']) {
                continue;
            }

            $out[$key] = [
                'status'       => (int) ($req['status'] ?? 0),
                'requested_by' => (string) ($req['requestedBy']['displayName']
                    ?? $req['requestedBy']['username']
                    ?? ''),
                'requested_at' => $createdAt,
            ];
        }

        return $this->json($out);
    }

    private function seerrConfigured(): bool
    {
        return $this->config->has('jellyseerr_api_key') && $this->config->has('jellyseerr_url');
    }
}
