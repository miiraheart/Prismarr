<?php

namespace App\Service\Media;

use App\Entity\ServiceInstance;
use App\Service\ServiceInstanceProvider;
use Psr\Log\LoggerInterface;

/**
 * Radarr + Sonarr library, indexed by TMDB id, with the per-title status the
 * cards badge with.
 *
 * This duplicates TmdbController::buildLibraryIndex() on purpose. That file is
 * upstream-owned and this fork fast-forward-syncs from upstream, so an edit
 * there is a cost paid again at every future sync, while a copy in a
 * fork-owned file costs nothing. Do not "fix" this by making TmdbController
 * call this service: that is the change this decision rejects. If a later task
 * has to edit TmdbController anyway, collapsing the two copies then is free.
 */
class LibraryIndex
{
    public function __construct(
        private readonly TmdbClient $tmdb,
        private readonly RadarrClient $radarr,
        private readonly SonarrClient $sonarr,
        private readonly ServiceInstanceProvider $instances,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function build(): array
    {
        $movieIds = [];
        $tvIds    = [];

        foreach ($this->instances->getEnabled(ServiceInstance::TYPE_RADARR) as $inst) {
            try {
                $movies = $this->radarr->withInstance($inst)->getMovies();
            } catch (\Throwable $e) {
                $this->logger->warning('LibraryIndex radarr failed', [
                    'instance'  => $inst->getSlug(),
                    'exception' => $e::class,
                    'message'   => $e->getMessage(),
                ]);
                continue;
            }
            foreach ($movies as $m) {
                if (empty($m['tmdbId'])) continue;
                $tmdbId = (int) $m['tmdbId'];
                if (isset($movieIds[$tmdbId])) continue; // first instance wins
                // Status: downloaded / missing / announced / inCinemas / unmonitored
                $hasFile   = !empty($m['hasFile']);
                $monitored = !empty($m['monitored']);
                $status    = $m['status'] ?? 'released';
                if (!$monitored) {
                    $libStatus = 'unmonitored';
                } elseif ($hasFile) {
                    $libStatus = 'downloaded';
                } elseif ($status === 'announced') {
                    $libStatus = 'announced';
                } elseif ($status === 'inCinemas') {
                    $libStatus = 'inCinemas';
                } else {
                    $libStatus = 'missing';
                }
                $movieIds[$tmdbId] = [
                    'id'     => (int) $m['id'],
                    'status' => $libStatus,
                    'slug'   => $inst->getSlug(),
                ];
            }
        }

        foreach ($this->instances->getEnabled(ServiceInstance::TYPE_SONARR) as $inst) {
            try {
                $allSeries = $this->sonarr->withInstance($inst)->getRawAllSeries();
            } catch (\Throwable $e) {
                $this->logger->warning('LibraryIndex sonarr failed', [
                    'instance'  => $inst->getSlug(),
                    'exception' => $e::class,
                    'message'   => $e->getMessage(),
                ]);
                continue;
            }
            foreach ($allSeries as $s) {
                $tvdbId = !empty($s['tvdbId']) ? (int) $s['tvdbId'] : null;
                $tmdbId = !empty($s['tmdbId']) ? (int) $s['tmdbId'] : null;
                $monitored    = !empty($s['monitored']);
                $stats        = $s['statistics'] ?? [];
                $fileCount    = (int) ($stats['episodeFileCount'] ?? 0);
                $totalEps     = (int) ($stats['episodeCount'] ?? 0);
                $seriesStatus = $s['status'] ?? '';

                if (!$monitored) {
                    $libStatus = 'unmonitored';
                } elseif ($totalEps > 0 && $fileCount >= $totalEps) {
                    $libStatus = 'downloaded';
                } elseif ($fileCount > 0) {
                    $libStatus = 'partial';
                } elseif ($seriesStatus === 'upcoming') {
                    $libStatus = 'announced';
                } else {
                    $libStatus = 'missing';
                }

                $info = [
                    'id'     => (int) $s['id'],
                    'status' => $libStatus,
                    'slug'   => $inst->getSlug(),
                ];

                if ($tvdbId && !isset($tvIds['tvdb_' . $tvdbId])) {
                    $tvIds['tvdb_' . $tvdbId] = $info;
                }
                if ($tmdbId && !isset($tvIds['tmdb_' . $tmdbId])) {
                    $tvIds['tmdb_' . $tmdbId] = $info;
                }

                if ($tvdbId && !$tmdbId) {
                    $resolved = $this->tmdb->findTmdbIdByTvdbId($tvdbId);
                    if ($resolved && !isset($tvIds['tmdb_' . $resolved])) {
                        $tvIds['tmdb_' . $resolved] = $info;
                    }
                }
            }
        }

        return ['movie' => $movieIds, 'tv' => $tvIds];
    }
}
