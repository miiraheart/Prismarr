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
            'cursor'  => is_array($data) ? ($data['next_cursor'] ?? null) : null,
        ];
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
