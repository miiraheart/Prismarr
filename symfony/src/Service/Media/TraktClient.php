<?php

namespace App\Service\Media;

use App\Exception\ServiceNotConfiguredException;
use App\Service\ConfigService;
use Psr\Cache\CacheItemPoolInterface;
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
    // Playback state changes while Mira is actively watching, so it gets a
    // much shorter TTL than the other reads: 15 minutes on a "currently
    // watching" badge would leave it stuck at a stale percentage.
    private const TTL_PLAYBACK = 300;
    // api.trakt.tv sits behind Cloudflare, which answers a UA-less request with
    // a 403 HTML block page instead of passing it to Trakt. PHP's curl sends no
    // User-Agent by default, so one has to be set explicitly.
    private const USER_AGENT  = 'Prismarr/1.0';
    // Reads need only the client id. Writes (removing a watchlist entry) are
    // OAuth: verified against the live API, POST /sync/watchlist/remove with
    // the client id alone answers 401. The device flow is used rather than the
    // authorization-code flow because it needs no reachable callback URL, which
    // matches the app registration's urn:ietf:wg:oauth:2.0:oob redirect.
    private const OAUTH_REDIRECT = 'urn:ietf:wg:oauth:2.0:oob';
    // Refresh this many seconds before the token actually lapses.
    private const TOKEN_MARGIN = 600;

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
     * Titles currently mid-watch ("Continue Watching" on Trakt): movies and
     * episodes that have a paused position but are not yet marked watched.
     *
     * OAuth only: verified against the live API, GET /sync/playback answers
     * 401 with just the client id, so this returns empty rather than trying
     * when the device flow has never been completed.
     *
     * @return array<string, array{progress:float, paused_at:?string, season:?int, episode:?int}> keyed "{type}:{tmdb_id}"
     */
    public function getPlayback(): array
    {
        try {
            $this->ensureConfig();
            $token = $this->accessToken();
            if ($token === null) {
                return [];
            }

            return $this->cachedGet('playback', function () use ($token): array {
                $res = $this->request('/sync/playback', [], $token);

                return is_array($res['data']) ? $this->mapPlayback($res['data']) : [];
            }, self::TTL_PLAYBACK);
        } catch (\Throwable $e) {
            $this->logger->warning('Trakt playback failed', ['exception' => $e::class, 'message' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Normalise a /sync/playback payload to Prismarr's vocabulary.
     *
     * The card this renders onto is show-level, so an episode entry is keyed
     * on the show's tmdb id, not the episode's. When several episodes of the
     * same show are mid-watch, the one paused most recently wins.
     *
     * @param array<int, array<string, mixed>> $raw
     * @return array<string, array{progress:float, paused_at:?string, season:?int, episode:?int}>
     */
    private function mapPlayback(array $raw): array
    {
        $out = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $kind = $entry['type'] ?? null;
            if ($kind === 'movie') {
                $media   = $entry['movie'] ?? null;
                $season  = null;
                $episode = null;
            } elseif ($kind === 'episode') {
                $media   = $entry['show'] ?? null;
                $season  = isset($entry['episode']['season']) ? (int) $entry['episode']['season'] : null;
                $episode = isset($entry['episode']['number']) ? (int) $entry['episode']['number'] : null;
            } else {
                continue;
            }

            $tmdb = $media['ids']['tmdb'] ?? null;
            if (!is_array($media) || !is_int($tmdb) || $tmdb <= 0) {
                continue;
            }

            // Trakt says "show", the rest of this codebase says "tv".
            $type     = $kind === 'movie' ? 'movie' : 'tv';
            $key      = "{$type}:{$tmdb}";
            $pausedAt = isset($entry['paused_at']) ? (string) $entry['paused_at'] : null;

            // ISO 8601 timestamps sort lexically, same trick used to order the
            // watchlist by listed_at in TraktController.
            if (isset($out[$key]) && (string) $out[$key]['paused_at'] >= (string) $pausedAt) {
                continue;
            }

            $out[$key] = [
                'progress'  => round((float) ($entry['progress'] ?? 0), 1),
                'paused_at' => $pausedAt,
                'season'    => $season,
                'episode'   => $episode,
            ];
        }

        return $out;
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
                    // Community score, already in the extended=full payload.
                    // Distinct from $entry['rating'] below, which is Mira's own.
                    'community_rating' => isset($media['rating']) ? round((float) $media['rating'], 1) : null,
                    'community_votes'  => isset($media['votes']) ? (int) $media['votes'] : null,
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
    private function cachedGet(string $key, callable $producer, int $ttl = self::TTL_LIST): array
    {
        $this->ensureConfig();
        // v2: bumped when the cached row shape changes, so an old entry is
        // never served against newer rendering code.
        $full = 'prismarr_trakt_v2_' . sha1($this->username . '_' . $key);

        return $this->cache->get($full, function (ItemInterface $item) use ($producer, $ttl) {
            $item->expiresAfter($ttl);

            return $producer();
        });
    }

    /**
     * @return array{data:?array, pageCount:int}
     */
    private function request(string $path, array $params = [], ?string $bearer = null): array
    {
        $this->ensureConfig();
        $url = self::BASE_URL . $path . '?' . http_build_query($params);

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'trakt-api-version: ' . self::API_VERSION,
            'trakt-api-key: ' . $this->clientId,
        ];
        if ($bearer !== null) {
            $headers[] = 'Authorization: Bearer ' . $bearer;
        }

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
            CURLOPT_HTTPHEADER     => $headers,
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

    // ── Write side (OAuth device flow) ───────────────────────────────────────

    /** True once the device flow has been completed and a token is stored. */
    public function hasWriteAccess(): bool
    {
        return (string) $this->config->get('trakt_access_token') !== '';
    }

    /** True when the client secret needed for the token exchange is present. */
    public function canStartDeviceAuth(): bool
    {
        return (string) $this->config->get('trakt_client_id') !== ''
            && (string) $this->config->get('trakt_client_secret') !== '';
    }

    /**
     * Begin the device flow. The user code is shown by the caller and typed by
     * the user at the returned verification URL. The device code is kept
     * server-side so the poll endpoint never has to trust client input.
     *
     * @return array{user_code:string, verification_url:string, interval:int, expires_in:int}|null
     */
    public function startDeviceAuth(): ?array
    {
        $clientId = (string) $this->config->get('trakt_client_id');
        if ($clientId === '') {
            return null;
        }

        $res = $this->postJson('/oauth/device/code', ['client_id' => $clientId], null);
        $data = $res['data'];
        if ($res['code'] !== 200 || !is_array($data) || !isset($data['device_code'], $data['user_code'])) {
            $this->logger->warning('Trakt device code request failed', ['http' => $res['code']]);

            return null;
        }

        $this->config->set('trakt_device_code', (string) $data['device_code']);

        return [
            'user_code'        => (string) $data['user_code'],
            'verification_url' => (string) ($data['verification_url'] ?? 'https://trakt.tv/activate'),
            'interval'         => (int) ($data['interval'] ?? 5),
            'expires_in'       => (int) ($data['expires_in'] ?? 600),
        ];
    }

    /**
     * Poll the pending device authorisation once.
     *
     * @return string one of: ok, pending, slow_down, expired, denied, none, error
     */
    public function pollDeviceAuth(): string
    {
        $deviceCode = (string) $this->config->get('trakt_device_code');
        if ($deviceCode === '') {
            return 'none';
        }

        $res = $this->postJson('/oauth/device/token', [
            'code'          => $deviceCode,
            'client_id'     => (string) $this->config->get('trakt_client_id'),
            'client_secret' => (string) $this->config->get('trakt_client_secret'),
        ], null);

        // Trakt's documented device-flow codes: 400 keep waiting, 404 unknown
        // code, 409 already used, 410 expired, 418 denied, 429 poll slower.
        return match ($res['code']) {
            200 => $this->storeToken($res['data']) ? 'ok' : 'error',
            400 => 'pending',
            429 => 'slow_down',
            404, 409, 410 => $this->forgetDeviceCode('expired'),
            418 => $this->forgetDeviceCode('denied'),
            default => 'error',
        };
    }

    /** Drop the stored tokens. The read side keeps working without them. */
    public function disconnect(): void
    {
        foreach (['trakt_access_token', 'trakt_refresh_token', 'trakt_token_expires', 'trakt_device_code'] as $key) {
            $this->config->set($key, null);
        }
    }

    /**
     * Remove one title from the Trakt watchlist itself.
     *
     * @param string $type movie|tv in Prismarr's vocabulary
     */
    public function removeFromWatchlist(int $tmdbId, string $type): bool
    {
        // Populates $this->username, which forgetCached() needs for the key.
        $this->ensureConfig();

        $token = $this->accessToken();
        if ($token === null) {
            return false;
        }

        // Back to Trakt's own vocabulary on the way out.
        $bucket = $type === 'movie' ? 'movies' : 'shows';
        $res = $this->postJson('/sync/watchlist/remove', [
            $bucket => [['ids' => ['tmdb' => $tmdbId]]],
        ], $token);

        $deleted = (int) ($res['data']['deleted'][$bucket] ?? 0);
        if ($res['code'] !== 200 || $deleted < 1) {
            $this->logger->warning('Trakt watchlist remove did not delete anything', [
                'http' => $res['code'], 'tmdb_id' => $tmdbId, 'bucket' => $bucket,
            ]);

            return false;
        }

        $this->forgetCached('watchlist');

        return true;
    }

    /**
     * Add a title to the Trakt watch history, which is what makes it count as
     * watched everywhere. Needed because a film watched outside Infuse never
     * scrobbles and so is missing from /watched.
     *
     * For a show this marks EVERY aired episode watched, which is Trakt's own
     * behaviour for a show-level history add; the caller warns about it.
     */
    public function markWatched(int $tmdbId, string $type): bool
    {
        $this->ensureConfig();
        $token = $this->accessToken();
        if ($token === null) {
            return false;
        }

        $bucket = $type === 'movie' ? 'movies' : 'shows';
        $res = $this->postJson('/sync/history', [
            $bucket => [['ids' => ['tmdb' => $tmdbId], 'watched_at' => 'released']],
        ], $token);

        $added = (int) ($res['data']['added']['movies'] ?? 0) + (int) ($res['data']['added']['episodes'] ?? 0);
        if ($res['code'] !== 201 && $res['code'] !== 200) {
            $this->logger->warning('Trakt history add failed', ['http' => $res['code'], 'tmdb_id' => $tmdbId]);

            return false;
        }
        if ($added < 1) {
            // Already in the history counts as success: the end state is right.
            $this->logger->info('Trakt history add matched nothing new', ['tmdb_id' => $tmdbId]);
        }

        $this->forgetCached('watched');

        return true;
    }

    /**
     * Drop a title. Trakt shipped a native Drop Show in 2025 and it is built
     * on the hidden-items API, not on a list: hiding a show under
     * progress_watched takes it out of Up Next and Progress, and under
     * calendar takes it off the calendar, while the watch history is kept.
     * Verified against the live API: /users/hidden/{section} answers 401
     * (exists, needs OAuth) where an invented route answers 404.
     *
     * progress_watched is a show/season section, so for a movie the only
     * meaningful equivalent is dropping it from the watchlist, which this
     * does for both kinds anyway.
     */
    public function markDropped(int $tmdbId, string $type): bool
    {
        $this->ensureConfig();
        $token = $this->accessToken();
        if ($token === null) {
            return false;
        }

        $bucket = $type === 'movie' ? 'movies' : 'shows';
        $ok = true;

        if ($type === 'tv') {
            $hidden = 0;
            foreach (['progress_watched', 'calendar'] as $section) {
                $res = $this->postJson('/users/hidden/' . $section, [
                    'shows' => [['ids' => ['tmdb' => $tmdbId]]],
                ], $token);
                $hidden += (int) ($res['data']['added']['shows'] ?? 0);
                if ($res['code'] !== 200 && $res['code'] !== 201) {
                    $this->logger->warning('Trakt hide failed', [
                        'section' => $section, 'http' => $res['code'], 'tmdb_id' => $tmdbId,
                    ]);
                    $ok = false;
                }
            }
            // Already hidden reports added=0, which is still the wanted state.
            if ($hidden === 0) {
                $this->logger->info('Trakt drop hid nothing new', ['tmdb_id' => $tmdbId]);
            }
        }

        // Dropping also clears the watchlist entry: it is no longer something
        // to watch, which is the whole point of pruning the list.
        $res = $this->postJson('/sync/watchlist/remove', [
            $bucket => [['ids' => ['tmdb' => $tmdbId]]],
        ], $token);
        if ($res['code'] !== 200) {
            $ok = false;
        }
        $this->forgetCached('watchlist');

        return $ok;
    }

    /**
     * A valid bearer token, refreshing it when it is close to lapsing.
     * Null when the device flow has never been completed.
     */
    private function accessToken(): ?string
    {
        $token = (string) $this->config->get('trakt_access_token');
        if ($token === '') {
            return null;
        }

        $expires = (int) $this->config->get('trakt_token_expires');
        if ($expires > 0 && $expires - self::TOKEN_MARGIN > time()) {
            return $token;
        }

        $refresh = (string) $this->config->get('trakt_refresh_token');
        if ($refresh === '') {
            return $token; // no refresh token: try the one we have and let the API judge
        }

        $res = $this->postJson('/oauth/token', [
            'refresh_token' => $refresh,
            'client_id'     => (string) $this->config->get('trakt_client_id'),
            'client_secret' => (string) $this->config->get('trakt_client_secret'),
            'redirect_uri'  => self::OAUTH_REDIRECT,
            'grant_type'    => 'refresh_token',
        ], null);

        if ($res['code'] === 200 && $this->storeToken($res['data'])) {
            return (string) $this->config->get('trakt_access_token');
        }

        $this->logger->warning('Trakt token refresh failed', ['http' => $res['code']]);

        return null;
    }

    /** @param array<string, mixed>|null $data */
    private function storeToken(?array $data): bool
    {
        if (!is_array($data) || !isset($data['access_token'])) {
            return false;
        }

        $createdAt = (int) ($data['created_at'] ?? time());
        $this->config->set('trakt_access_token', (string) $data['access_token']);
        $this->config->set('trakt_refresh_token', (string) ($data['refresh_token'] ?? ''));
        $this->config->set('trakt_token_expires', (string) ($createdAt + (int) ($data['expires_in'] ?? 0)));
        $this->config->set('trakt_device_code', null);

        return true;
    }

    private function forgetDeviceCode(string $verdict): string
    {
        $this->config->set('trakt_device_code', null);

        return $verdict;
    }

    /**
     * Best-effort cache drop so a removal shows up before the 15 minute TTL.
     * CacheInterface has no delete(); the app pool also implements the PSR-6
     * pool, so downcast when it does and simply wait out the TTL when it does not.
     */
    private function forgetCached(string $key): void
    {
        if ($this->cache instanceof CacheItemPoolInterface && $this->username !== '') {
            $this->cache->deleteItem('prismarr_trakt_v2_' . sha1($this->username . '_' . $key));
        }
    }

    /**
     * @param array<string, mixed> $body
     * @return array{code:int, data:?array}
     */
    private function postJson(string $path, array $body, ?string $bearer): array
    {
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'trakt-api-version: ' . self::API_VERSION,
            'trakt-api-key: ' . (string) $this->config->get('trakt_client_id'),
        ];
        if ($bearer !== null) {
            $headers[] = 'Authorization: Bearer ' . $bearer;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => self::BASE_URL . $path,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_NOSIGNAL       => 1,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return ['code' => $code, 'data' => is_array($decoded) ? $decoded : null];
    }
}
