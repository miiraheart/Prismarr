<?php

namespace App\Service\Media;

use App\Exception\ServiceNotConfiguredException;
use App\Service\ConfigService;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Read-only MDBList client.
 *
 * MDBList returns IMDb, Rotten Tomatoes, Metacritic, Letterboxd and Trakt
 * scores from one credential, and its list items already carry a TMDB id, so
 * nothing here needs a resolution step.
 *
 * Cache-only, like TraktClient: no entities and no migrations.
 */
class MdblistClient implements ResetInterface
{
    private const SERVICE  = 'MDBList';
    private const BASE_URL = 'https://api.mdblist.com';
    // api.mdblist.com answers a User-Agent-less request with 403, the same way
    // api.trakt.tv does. PHP's curl sends none by default.
    private const USER_AGENT = 'Prismarr/1.0';

    private string $apiKey = '';

    public function __construct(
        private readonly ConfigService $config,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {}

    private function ensureConfig(): void
    {
        if ($this->config->get('mdblist_enabled') === '0') {
            throw new ServiceNotConfiguredException(self::SERVICE, 'mdblist_enabled');
        }
        if ($this->apiKey === '') {
            $this->apiKey = $this->config->require('mdblist_api_key', self::SERVICE);
        }
    }

    /** @see TmdbClient::reset() for why cached config is dropped per worker request. */
    public function reset(): void
    {
        $this->apiKey = '';
    }

    /** Light ping: /user is the cheapest authenticated call and needs no ids. */
    public function ping(): bool
    {
        try {
            $this->ensureConfig();

            return is_array($this->request('/user')['data']);
        } catch (\Throwable $e) {
            $this->logger->warning('MDBList ping failed', ['exception' => $e::class, 'message' => $e->getMessage()]);

            return false;
        }
    }

    private const TTL_DIRECTORY = 3600;

    /**
     * The website's own toplists ranking, which is the directory the Lists tab
     * opens on.
     *
     * @return list<array{id:int, name:string, slug:string, user:string, items:int, likes:int, mediatype:string, url:string}>
     */
    public function getTopLists(int $limit = 60): array
    {
        return $this->cachedGet('top_' . $limit, function () use ($limit): array {
            return $this->mapListRows($this->request('/lists/top', ['limit' => $limit])['data'] ?? []);
        });
    }

    /** @return list<array{id:int, name:string, slug:string, user:string, items:int, likes:int, mediatype:string, url:string}> */
    public function searchLists(string $query, int $limit = 60): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        return $this->cachedGet('search_' . sha1($query) . '_' . $limit, function () use ($query, $limit): array {
            return $this->mapListRows($this->request('/lists/search', ['query' => $query, 'limit' => $limit])['data'] ?? []);
        });
    }

    /**
     * @param  array<int, array<string, mixed>> $raw
     * @return list<array{id:int, name:string, slug:string, user:string, items:int, likes:int, mediatype:string, url:string}>
     */
    private function mapListRows(array $raw): array
    {
        $rows = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $slug = (string) ($row['slug'] ?? '');
            $user = (string) ($row['user_name'] ?? '');
            // Without both, the list cannot be opened or pinned.
            if ($slug === '' || $user === '') {
                continue;
            }

            $rows[] = [
                'id'        => (int) ($row['id'] ?? 0),
                'name'      => (string) ($row['name'] ?? $slug),
                'slug'      => $slug,
                'user'      => $user,
                'items'     => (int) ($row['items'] ?? 0),
                'likes'     => (int) ($row['likes'] ?? 0),
                'mediatype' => (string) ($row['mediatype'] ?? ''),
                'url'       => 'https://mdblist.com/lists/' . $user . '/' . $slug,
            ];
        }

        return $rows;
    }

    private const PAGE_LIMIT = 100;

    /**
     * One page of a list.
     *
     * Paging is cursor based: the caller passes back the `next_cursor` from the
     * previous answer until it is absent. The `offset` parameter still works
     * but is deprecated in the API.
     *
     * @return array{items:list<array<string,mixed>>, next_cursor:?string, total:int}
     */
    public function getListItems(string $user, string $slug, ?string $cursor = null, int $limit = self::PAGE_LIMIT): array
    {
        $key = 'items_' . sha1($user . '/' . $slug . '/' . ($cursor ?? '') . '/' . $limit);

        return $this->cachedGet($key, function () use ($user, $slug, $cursor, $limit): array {
            $res = $this->request(
                '/lists/' . rawurlencode($user) . '/' . rawurlencode($slug) . '/items',
                [
                    'limit'  => $limit,
                    'cursor' => $cursor,
                    // Ratings ride along with the items, which is far cheaper
                    // than the batch rating endpoint: that one is capped at 10
                    // ids per request for a non-supporter key.
                    'append_to_response' => 'poster,ratings',
                ],
            );

            return [
                'items'       => $this->mapItems($res['data'] ?? []),
                'next_cursor' => $res['cursor'],
                'total'       => $res['total'],
            ];
        });
    }

    /**
     * Flatten MDBList's movies/shows split into the card shape that
     * renderCardHTML() in decouverte/_detail_modal.html.twig already reads.
     *
     * @param  array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private function mapItems(array $payload): array
    {
        $out = [];
        foreach (['movies' => 'movie', 'shows' => 'tv'] as $bucket => $type) {
            foreach ((array) ($payload[$bucket] ?? []) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $tmdb = $row['ids']['tmdb'] ?? null;
                if (!is_int($tmdb) || $tmdb <= 0) {
                    continue;
                }

                $out[] = [
                    'id'         => $tmdb,
                    // MDBList says "show", the rest of this codebase says "tv".
                    'type'       => $type,
                    'title'      => (string) ($row['title'] ?? ''),
                    'year'       => isset($row['release_year']) ? (int) $row['release_year'] : null,
                    'imdb'       => $row['ids']['imdb'] ?? ($row['imdb_id'] ?? null),
                    'poster'     => $this->posterFrom($row),
                    'vote'       => $this->voteFrom($row),
                    'in_library' => false,
                    'lib_status' => null,
                    'lib_id'     => null,
                ];
            }
        }

        return $out;
    }

    /**
     * MEASURED 2026-08-23: every item carries an absolute
     * https://image.tmdb.org/t/p/w200/... URL, so the grid needs no per-title
     * TMDb hydration at all. w342 is the size Decouverte's own cards use.
     */
    private function posterFrom(array $row): ?string
    {
        $poster = $row['poster'] ?? null;
        if (!is_string($poster) || !str_starts_with($poster, 'http')) {
            return null;
        }

        return str_replace('/t/p/w200/', '/t/p/w342/', $poster);
    }

    /**
     * MEASURED 2026-08-23: `ratings` is an ARRAY of {source, value, score,
     * votes}, not a map, and the scales differ per source: imdb `value` is
     * 0 to 10 (8.0) while tmdb `value` is 0 to 100 (82). The shared card badge
     * expects TMDb's native 0-to-10 scale, so prefer imdb's value and fall
     * back to a tmdb score divided by 10.
     */
    private function voteFrom(array $row): ?float
    {
        $ratings = $row['ratings'] ?? null;
        if (!is_array($ratings)) {
            return null;
        }

        $bySource = [];
        foreach ($ratings as $rating) {
            if (is_array($rating) && isset($rating['source'])) {
                $bySource[(string) $rating['source']] = $rating;
            }
        }

        $imdb = $bySource['imdb']['value'] ?? null;
        if (is_numeric($imdb) && $imdb > 0) {
            return round((float) $imdb, 1);
        }

        $tmdb = $bySource['tmdb']['score'] ?? null;
        if (is_numeric($tmdb) && $tmdb > 0) {
            return round((float) $tmdb / 10, 1);
        }

        return null;
    }

    /**
     * @param callable():array $producer
     */
    private function cachedGet(string $key, callable $producer): array
    {
        $this->ensureConfig();
        $full = 'prismarr_mdblist_v1_' . sha1($key);

        return $this->cache->get($full, function (\Symfony\Contracts\Cache\ItemInterface $item) use ($producer) {
            $item->expiresAfter(self::TTL_DIRECTORY);

            return $producer();
        });
    }

    /**
     * The credential is a query parameter, not a header: the OpenAPI schema at
     * api.mdblist.com/schema/ declares only `apikey` in query plus a bearer
     * token for OAuth, so a header is silently ignored and the call 401s.
     */
    private function buildUrl(string $path, array $params): string
    {
        $this->ensureConfig();
        $params['apikey'] = $this->apiKey;
        $params = array_filter($params, static fn ($v): bool => $v !== null && $v !== '');

        return self::BASE_URL . $path . '?' . http_build_query($params);
    }

    /**
     * @return array{data:?array, hasMore:bool, total:int, cursor:?string}
     */
    private function request(string $path, array $params = []): array
    {
        $this->ensureConfig();

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->buildUrl($path, $params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_NOSIGNAL       => 1,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            // Same SSRF guard as the other clients in this namespace.
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER      => ['Accept: application/json'],
        ]);

        $raw        = curl_exec($ch);
        $code       = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $err        = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $code >= 400) {
            // 429 is the documented rate-limit answer. Everything degrades to
            // an empty row rather than breaking the page.
            $this->logger->warning("MDBList {$path} failed (HTTP {$code}) : {$err}");

            return ['data' => null, 'hasMore' => false, 'total' => 0, 'cursor' => null];
        }

        $headers = substr($raw, 0, $headerSize);
        $body    = substr($raw, $headerSize);
        $data    = json_decode($body, true);

        return [
            'data'    => is_array($data) ? $data : null,
            'hasMore' => strtolower(trim($this->header($headers, 'x-has-more'))) === 'true',
            'total'   => (int) $this->header($headers, 'x-total-items'),
            'cursor'  => $this->cursorFrom(is_array($data) ? $data : null),
        ];
    }

    /**
     * The paging cursor is nested under `pagination`, measured against the live
     * API on 2026-08-23. Reading it from the top level silently stops paging
     * after the first page.
     */
    private function cursorFrom(?array $data): ?string
    {
        $cursor = $data['pagination']['next_cursor'] ?? null;

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    /**
     * Phase 2 folds this and TraktClient's identical helper into the shared
     * TraktHttp/Http plumbing the spec calls for. Six lines is not worth a
     * trait before the second caller exists.
     */
    private function header(string $headers, string $name): string
    {
        foreach (explode("\r\n", $headers) as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2 && strtolower(trim($parts[0])) === $name) {
                return trim($parts[1]);
            }
        }

        return '';
    }
}
