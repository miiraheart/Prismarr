<?php

namespace App\Service\Media\Discover;

/**
 * A pasted list URL to the pieces a client needs to fetch it.
 *
 * Phase 1 answers MDBList only. Trakt lists arrive with TraktDiscoverClient in
 * phase 2, Letterboxd and IMDb with their scrapers in phase 5. Everything else
 * returns null so the caller can say "unsupported" instead of failing later.
 */
final class ListSourceResolver
{
    /** @return array{source:string, user:string, slug:string}|null */
    public static function parse(string $url): ?array
    {
        $url = trim($url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return null;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        if ($host !== 'mdblist.com' && $host !== 'www.mdblist.com') {
            return null;
        }

        $parts = explode('/', $path);
        if (count($parts) < 3 || $parts[0] !== 'lists' || $parts[1] === '' || $parts[2] === '') {
            return null;
        }

        return ['source' => 'mdblist', 'user' => $parts[1], 'slug' => $parts[2]];
    }
}
