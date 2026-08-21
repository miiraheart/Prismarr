<?php

namespace App\Service\Media;

use App\Exception\ServiceNotConfiguredException;
use App\Service\ConfigService;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Read-only Trakt client.
 *
 * Reads a *public* Trakt profile, which needs only the client id (API key) and
 * no OAuth at all. Trakt marks the /users endpoints as OAuth-optional: with a
 * public profile the client id alone is enough, and a private profile returns
 * 401/403, which is surfaced as a configuration problem rather than a crash.
 */
class TraktClient implements ResetInterface
{
    private const SERVICE     = 'Trakt';
    private const BASE_URL    = 'https://api.trakt.tv';
    private const API_VERSION = '2';
    private const PAGE_LIMIT  = 100;
    private const MAX_PAGES   = 50;
    private const TTL_LIST    = 900;
    // api.trakt.tv sits behind Cloudflare, which answers a UA-less request with
    // a 403 HTML block page instead of passing it to Trakt. PHP's curl sends no
    // User-Agent by default, so one has to be set explicitly.
    private const USER_AGENT  = 'Prismarr/1.0';

    private string $clientId = '';
    private string $username = '';

    public function __construct(
        private readonly ConfigService $config,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {}

    private function ensureConfig(): void
    {
        if ($this->config->get('trakt_enabled') === '0') {
            throw new ServiceNotConfiguredException(self::SERVICE, 'trakt_enabled');
        }
        if ($this->clientId === '') {
            $this->clientId = $this->config->require('trakt_client_id', self::SERVICE);
        }
        if ($this->username === '') {
            $this->username = $this->config->require('trakt_username', self::SERVICE);
        }
    }

    /** @see TmdbClient::reset() for why cached config is dropped per worker request. */
    public function reset(): void
    {
        $this->clientId = '';
        $this->username = '';
    }

    /** Light ping: proves the client id works *and* the profile is publicly readable. */
    public function ping(): bool
    {
        try {
            $this->ensureConfig();
            $slug = rawurlencode($this->username);

            return $this->request("/users/{$slug}/watchlist/movies", ['limit' => 1])['data'] !== null;
        } catch (\Throwable $e) {
            $this->logger->warning('Trakt ping failed', ['exception' => $e::class, 'message' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Watchlist entries, newest first, normalised to Prismarr's own vocabulary.
     *
     * @return array<int, array{tmdb_id:int, type:string, title:string, year:?int, trakt_id:?int, listed_at:?string}>
     */
    public function getWatchlist(): array
    {
        return $this->cachedGet('watchlist', function (): array {
            return array_merge(
                $this->collect('watchlist', 'movies'),
                $this->collect('watchlist', 'shows'),
            );
        });
    }

    /**
     * Personal ratings, movie and show level only.
     *
     * Season and episode ratings are deliberately not fetched: they can run to
     * five figures on an episode-rating habit, and nothing in Prismarr renders
     * below show level.
     *
     * @return array<string, array{rating:int, rated_at:?string}> keyed "{type}:{tmdb_id}"
     */
    public function getRatings(): array
    {
        return $this->cachedGet('ratings', function (): array {
            $out = [];
            foreach (['movies', 'shows'] as $bucket) {
                foreach ($this->collect('ratings', $bucket) as $row) {
                    $out["{$row['type']}:{$row['tmdb_id']}"] = [
                        'rating'   => $row['rating'],
                        'rated_at' => $row['rated_at'],
                    ];
                }
            }

            return $out;
        });
    }

    /**
     * Aggregate watched state, movie and show level.
     *
     * Uses /watched (current state: plays + last watched) rather than /history
     * (the full event log), because Prismarr only ever asks "have I seen this",
     * never "when did I see it the third time". /history on an account fed by a
     * scrobbler is orders of magnitude larger for no gain here.
     *
     * @return array<string, array{plays:int, last_watched_at:?string}> keyed "{type}:{tmdb_id}"
     */
    public function getWatched(): array
    {
        return $this->cachedGet('watched', function (): array {
            $out = [];
            foreach (['movies', 'shows'] as $bucket) {
                foreach ($this->collect('watched', $bucket) as $row) {
                    $out["{$row['type']}:{$row['tmdb_id']}"] = [
                        'plays'           => $row['plays'] ?? 0,
                        'last_watched_at' => $row['last_watched_at'],
                    ];
                }
            }

            return $out;
        });
    }

    /**
     * Page through one /users/{slug}/{section}/{bucket} collection.
     *
     * @return array<int, array<string, mixed>>
     */
    private function collect(string $section, string $bucket): array
    {
        $this->ensureConfig();
        $slug     = rawurlencode($this->username);
        $singular = $bucket === 'movies' ? 'movie' : 'show';
        $type     = $bucket === 'movies' ? 'movie' : 'tv';
        $rows     = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $res = $this->request("/users/{$slug}/{$section}/{$bucket}", [
                'page'     => $page,
                'limit'    => self::PAGE_LIMIT,
                'extended' => 'full',
            ]);

            if (!is_array($res['data'])) {
                break;
            }

            foreach ($res['data'] as $entry) {
                $media = $entry[$singular] ?? null;
                $tmdb  = $media['ids']['tmdb'] ?? null;

                // Without a TMDB id there is nothing to join against: Prismarr,
                // Radarr and Sonarr are all keyed on tmdb_id.
                if (!is_array($media) || !is_int($tmdb) || $tmdb <= 0) {
                    continue;
                }

                $rows[] = [
                    'tmdb_id'   => $tmdb,
                    // Trakt says "show", the rest of this codebase says "tv".
                    // Normalise once, here, so nothing downstream has to care.
                    'type'      => $type,
                    'title'     => (string) ($media['title'] ?? ''),
                    'year'      => isset($media['year']) ? (int) $media['year'] : null,
                    'trakt_id'  => $media['ids']['trakt'] ?? null,
                    'listed_at' => $entry['listed_at'] ?? null,
                    'rating'    => isset($entry['rating']) ? (int) $entry['rating'] : null,
                    'rated_at'  => $entry['rated_at'] ?? null,
                    // /watched wraps these at the entry level, not inside the media object.
                    'plays'           => isset($entry['plays']) ? (int) $entry['plays'] : null,
                    'last_watched_at' => $entry['last_watched_at'] ?? null,
                ];
            }

            if ($page >= (int) ($res['pageCount'] ?? 1)) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @param callable():array $producer
     */
    private function cachedGet(string $key, callable $producer): array
    {
        $this->ensureConfig();
        $full = 'prismarr_trakt_' . sha1($this->username . '_' . $key);

        return $this->cache->get($full, function (ItemInterface $item) use ($producer) {
            $item->expiresAfter(self::TTL_LIST);

            return $producer();
        });
    }

    /**
     * @return array{data:?array, pageCount:int}
     */
    private function request(string $path, array $params = []): array
    {
        $this->ensureConfig();
        $url = self::BASE_URL . $path . '?' . http_build_query($params);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_NOSIGNAL       => 1,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            // Same SSRF guard as the other clients in this namespace.
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Content-Type: application/json',
                'trakt-api-version: ' . self::API_VERSION,
                'trakt-api-key: ' . $this->clientId,
            ],
        ]);

        $raw        = curl_exec($ch);
        $code       = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $err        = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $code >= 400) {
            // 401/403 here means the profile is private, not that the key is wrong.
            $this->logger->warning("Trakt {$path} failed (HTTP {$code}) : {$err}");

            return ['data' => null, 'pageCount' => 1];
        }

        $headers = substr($raw, 0, $headerSize);
        $body    = substr($raw, $headerSize);
        $data    = json_decode($body, true);

        return [
            'data'      => is_array($data) ? $data : null,
            'pageCount' => $this->headerInt($headers, 'x-pagination-page-count'),
        ];
    }

    /** Trakt reports total pages in a response header, not in the body. */
    private function headerInt(string $headers, string $name): int
    {
        foreach (preg_split('/\r?\n/', $headers) as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2 && strtolower(trim($parts[0])) === $name) {
                return max(1, (int) trim($parts[1]));
            }
        }

        return 1;
    }
}
