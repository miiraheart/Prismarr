<?php

namespace App\Service\Media\Discover;

use App\Service\ConfigService;
use App\Service\Media\LibraryIndex;
use App\Service\Media\MdblistClient;
use Psr\Log\LoggerInterface;

/**
 * Everything the Lists view needs, shared by its two callers.
 *
 * Extracted from DiscoverController at the tabbed merge. DiscoverController
 * still owns every /lists/* JSON route and DiscoverPageController renders the
 * Lists tab, and both need the same pinned-list handling, the same directory
 * assembly and the same library badging. Keeping the logic here means one
 * implementation rather than two that drift.
 */
class ListsTabContext
{
    public const PINNED_KEY = 'discover_pinned_lists';

    // Mirrors ROW_CAP in discover/_tab_lists.html.twig: the free MDBList plan
    // allows 1000 requests/day, so the directory (up to 60 lists) is capped
    // before it ever reaches the row-shell markup or the client.
    public const ROW_CAP = 24;

    // Rows fetched and rendered server-side so the top of the page is
    // complete on arrival. Rows beyond this stay lazy, loaded by the
    // IntersectionObserver in the template as they scroll into view.
    public const INLINE_ROWS = 3;

    public function __construct(
        private readonly MdblistClient   $mdblist,
        private readonly LibraryIndex    $libraryIndex,
        private readonly ConfigService   $config,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * The Twig context for the Lists tab.
     *
     * @return array<string, mixed>
     */
    public function build(): array
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

        return [
            'pinned'      => $this->pinned(),
            'configured'  => $this->config->has('mdblist_api_key'),
            'rows'        => $shown,
            'totalRows'   => $totalRows,
            'rowCap'      => self::ROW_CAP,
            'inlineRows'  => self::INLINE_ROWS,
            'initialRows' => $initialRows,
        ];
    }

    /**
     * Badge each item with Radarr/Sonarr library status.
     *
     * @param  list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public function withLibraryStatus(array $items): array
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
    public function pinned(): array
    {
        $raw = $this->config->get(self::PINNED_KEY);
        if ($raw === null) {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * Server-side twin of buildRows() in discover/_tab_lists.html.twig: pinned
     * lists first, then the toplists directory with any list already pinned
     * filtered out so it never renders as two identical rows.
     *
     * @return list<array{url:string, label:string, pinned:bool, source?:string, user?:string, items?:int, likes?:int}>
     */
    public function directoryRows(): array
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
    public function firstPageItems(string $url): array
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
    public function pinKey(string $url): string
    {
        return strtolower(rtrim(trim($url), '/'));
    }
}
