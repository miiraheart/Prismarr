<?php

namespace App\Service\Media;

/**
 * Fork-owned copy of TmdbController::enrich().
 *
 * Deliberately a copy, not a shared extraction. TmdbController is upstream
 * owned and fast-forward syncs from upstream nightly, so an edit there is a
 * cost re-paid at every sync while a fork-owned copy is paid once. Same
 * ruling as LibraryIndex, which copies TmdbController::buildLibraryIndex()
 * for the same reason.
 *
 * Keep this byte-identical to the original apart from the signature. If the
 * upstream version changes, TmdbEnricherTest is what makes the divergence
 * visible.
 */
class TmdbEnricher
{
    /**
     * @param array<int, array<string, mixed>> $items  raw TMDb results
     * @param array{movie: array<int, array{id: int, status: string}>, tv: array<string, array{id: int, status: string}>} $library
     * @return list<array<string, mixed>>
     */
    public function enrich(array $items, array $library, ?string $forceType = null): array
    {
        $out = [];
        foreach ($items as $it) {
            $type = $forceType ?? ($it['media_type'] ?? null);
            if (!in_array($type, ['movie', 'tv'], true)) {
                continue;
            }

            $title = $type === 'movie' ? ($it['title'] ?? '') : ($it['name'] ?? '');
            $date  = $type === 'movie' ? ($it['release_date'] ?? null) : ($it['first_air_date'] ?? null);
            $year  = $date ? (int) substr($date, 0, 4) : null;

            $libInfo = null;
            if ($type === 'movie') {
                $libInfo = $library['movie'][(int) ($it['id'] ?? 0)] ?? null;
            } else {
                $libInfo = $library['tv']['tmdb_' . (int) ($it['id'] ?? 0)] ?? null;
            }

            $out[] = [
                'id'          => (int) ($it['id'] ?? 0),
                'type'        => $type,
                'title'       => $title,
                'year'        => $year,
                'release'     => $date,
                'overview'    => $it['overview']      ?? null,
                'poster'      => TmdbClient::posterUrl($it['poster_path'] ?? null, 'w342'),
                'poster_hi'   => TmdbClient::posterUrl($it['poster_path'] ?? null, 'w500'),
                'backdrop'    => TmdbClient::backdropUrl($it['backdrop_path'] ?? null, 'w780'),
                'vote'        => isset($it['vote_average']) ? round((float) $it['vote_average'], 1) : null,
                'vote_count'  => (int) ($it['vote_count'] ?? 0),
                'popularity'  => $it['popularity'] ?? null,
                'in_library'  => $libInfo !== null,
                'lib_status'  => $libInfo['status'] ?? null,
                'lib_id'      => $libInfo['id'] ?? null,
            ];
        }
        return $out;
    }
}
