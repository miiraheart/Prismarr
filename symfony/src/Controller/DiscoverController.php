<?php

namespace App\Controller;

use App\Service\ConfigService;
use App\Service\Media\Discover\ListSourceResolver;
use App\Service\Media\LibraryIndex;
use App\Service\Media\MdblistClient;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Discover v2, phase 1: the Lists tab.
 *
 * A new page rather than an extension of /decouverte, which is an upstream
 * file that already diverges after the detail-modal extraction. Everything
 * here reads through MdblistClient's cache: no entities, no migrations, and
 * pinned lists live in a single `setting` row as JSON.
 */
#[IsGranted('ROLE_USER')]
#[Route('/lists', name: 'lists_')]
class DiscoverController extends AbstractController
{
    private const PINNED_KEY = 'discover_pinned_lists';

    // Mirrors ROW_CAP in discover/index.html.twig: the free MDBList plan
    // allows 1000 requests/day, so the directory (up to 60 lists) is capped
    // before it ever reaches the row-shell markup or the client.
    private const ROW_CAP = 24;

    // Rows fetched and rendered server-side so the top of the page is
    // complete on arrival. Rows beyond this stay lazy, loaded by the
    // IntersectionObserver in the template as they scroll into view.
    private const INLINE_ROWS = 3;

    public function __construct(
        private readonly MdblistClient  $mdblist,
        private readonly LibraryIndex   $libraryIndex,
        private readonly ConfigService  $config,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('', name: 'index')]
    public function index(): Response
    {
        $rows      = $this->directoryRows();
        $totalRows = count($rows);
        $shown     = array_slice($rows, 0, self::ROW_CAP);

        // Only the first few rows are fetched here: every row would mean up
        // to ROW_CAP round trips against MDBList on every page view, which
        // the lazy rows exist specifically to avoid.
        $initialRows = [];
        foreach (array_slice($shown, 0, self::INLINE_ROWS) as $row) {
            $initialRows[$row['url']] = $this->firstPageItems($row['url']);
        }

        return $this->render('discover/index.html.twig', [
            'pinned'      => $this->pinned(),
            'configured'  => $this->config->has('mdblist_api_key'),
            'rows'        => $shown,
            'totalRows'   => $totalRows,
            'rowCap'      => self::ROW_CAP,
            'inlineRows'  => self::INLINE_ROWS,
            'initialRows' => $initialRows,
        ]);
    }

    /** The Lists tab default view: the MDBList toplists directory, or a search over it. */
    #[Route('/directory', name: 'directory', methods: ['GET'])]
    public function lists(Request $request): JsonResponse
    {
        $query = trim((string) $request->query->get('q', ''));

        try {
            $lists = $query === ''
                ? $this->mdblist->getTopLists()
                : $this->mdblist->searchLists($query);
        } catch (\Throwable $e) {
            $this->logger->warning('Discover lists failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $lists = [];
        }

        return $this->json(['lists' => $lists, 'pinned' => $this->pinned()]);
    }

    /** One list, as a page of cards. */
    #[Route('/items', name: 'items', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $src    = (string) $request->query->get('src', '');
        $cursor = $request->query->get('cursor');
        $cursor = is_string($cursor) && $cursor !== '' ? $cursor : null;

        $parsed = ListSourceResolver::parse($src);
        if ($parsed === null) {
            return $this->json([
                'items'       => [],
                'next_cursor' => null,
                'error'       => 'unsupported_source',
            ]);
        }

        try {
            $page = $this->mdblist->getListItems($parsed['user'], $parsed['slug'], $cursor);
        } catch (\Throwable $e) {
            $this->logger->warning('Discover list failed', ['src' => $src, 'message' => $e->getMessage()]);

            return $this->json(['items' => [], 'next_cursor' => null, 'error' => 'unavailable']);
        }

        return $this->json([
            'items'       => $this->withLibraryStatus($page['items']),
            'next_cursor' => $page['next_cursor'],
            'total'       => $page['total'],
            'error'       => null,
        ]);
    }

    // No CSRF token: internal app, routes protected by the class-level IsGranted.
    // Same call as TraktController's write routes.
    #[Route('/pin', name: 'pin', methods: ['POST'])]
    public function pin(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $url     = trim((string) ($payload['url'] ?? ''));
        $parsed  = ListSourceResolver::parse($url);

        if ($parsed === null) {
            return $this->json(['ok' => false, 'error' => 'unsupported_source'], 400);
        }

        // Canonical, not the submitted string: strips any query string or
        // fragment an attacker could smuggle through the pin request before
        // it is persisted and later replayed into the pinned-lists JSON.
        $canonicalUrl = 'https://mdblist.com/lists/' . $parsed['user'] . '/' . $parsed['slug'];
        $id           = ListSourceResolver::idFor($canonicalUrl);
        $pinned       = $this->pinned();
        foreach ($pinned as $row) {
            if (($row['id'] ?? '') === $id) {
                return $this->json(['ok' => true, 'pinned' => $pinned]);
            }
        }

        $pinned[] = [
            'id'       => $id,
            'url'      => $canonicalUrl,
            'source'   => $parsed['source'],
            'label'    => trim((string) ($payload['label'] ?? '')) ?: $parsed['slug'],
            'added_at' => date(DATE_ATOM),
        ];
        $this->config->set(self::PINNED_KEY, json_encode(array_values($pinned)));

        return $this->json(['ok' => true, 'pinned' => $pinned]);
    }

    #[Route('/unpin', name: 'unpin', methods: ['POST'])]
    public function unpin(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $id      = trim((string) ($payload['id'] ?? ''));

        $pinned = array_values(array_filter(
            $this->pinned(),
            static fn (array $row): bool => ($row['id'] ?? '') !== $id,
        ));
        $this->config->set(self::PINNED_KEY, json_encode($pinned));

        return $this->json(['ok' => true, 'pinned' => $pinned]);
    }

    /**
     * Badge the titles Radarr or Sonarr already owns. The spec's rule is badge
     * everything and hide nothing, so a watched or owned title still renders.
     *
     * @param  list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function withLibraryStatus(array $items): array
    {
        try {
            $library = $this->libraryIndex->build();
        } catch (\Throwable $e) {
            $this->logger->warning('Discover library index failed', ['message' => $e->getMessage()]);

            return $items;
        }

        foreach ($items as $i => $item) {
            $info = $item['type'] === 'movie'
                ? ($library['movie'][$item['id']] ?? null)
                : ($library['tv']['tmdb_' . $item['id']] ?? null);

            if ($info !== null) {
                $items[$i]['in_library'] = true;
                $items[$i]['lib_status'] = $info['status'];
                $items[$i]['lib_id']     = $info['id'];
            }
        }

        return $items;
    }

    /** @return list<array{id:string, url:string, source:string, label:string, added_at:string}> */
    private function pinned(): array
    {
        $raw = $this->config->get(self::PINNED_KEY);
        if ($raw === null) {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * Server-side twin of buildRows() in discover/index.html.twig: pinned
     * lists first, then the toplists directory with any list already pinned
     * filtered out so it never renders as two identical rows.
     *
     * @return list<array{url:string, label:string, pinned:bool, source?:string, user?:string, items?:int, likes?:int}>
     */
    private function directoryRows(): array
    {
        try {
            $lists = $this->mdblist->getTopLists();
        } catch (\Throwable $e) {
            $this->logger->warning('Discover directory failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $lists = [];
        }

        $seen = [];
        $rows = [];
        foreach ($this->pinned() as $p) {
            $rows[] = [
                'url'    => $p['url'],
                'label'  => $p['label'],
                'pinned' => true,
                'source' => $p['source'],
            ];
            $seen[$this->pinKey($p['url'])] = true;
        }

        foreach ($lists as $l) {
            if (isset($seen[$this->pinKey($l['url'])])) {
                continue;
            }
            $rows[] = [
                'url'    => $l['url'],
                'label'  => $l['name'],
                'pinned' => false,
                'user'   => $l['user'],
                'items'  => $l['items'],
                'likes'  => $l['likes'],
            ];
        }

        return $rows;
    }

    /**
     * One row's first page, fetched and library-badged the same way /lists/items
     * answers it, for the rows inlined straight into the page.
     *
     * @return array{items:list<array<string, mixed>>, next_cursor:?string}
     */
    private function firstPageItems(string $url): array
    {
        $parsed = ListSourceResolver::parse($url);
        if ($parsed === null) {
            return ['items' => [], 'next_cursor' => null];
        }

        try {
            $page = $this->mdblist->getListItems($parsed['user'], $parsed['slug']);
        } catch (\Throwable $e) {
            $this->logger->warning('Discover directory row failed', ['url' => $url, 'message' => $e->getMessage()]);

            return ['items' => [], 'next_cursor' => null];
        }

        return [
            'items'       => $this->withLibraryStatus($page['items']),
            'next_cursor' => $page['next_cursor'],
        ];
    }

    /** Same identity rule as the client's pinKey(): trailing slash and case are both ignored. */
    private function pinKey(string $url): string
    {
        return strtolower(rtrim(trim($url), '/'));
    }
}
