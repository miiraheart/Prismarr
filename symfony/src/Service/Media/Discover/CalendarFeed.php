<?php

namespace App\Service\Media\Discover;

use App\Entity\ServiceInstance;
use App\Service\ConfigService;
use App\Service\Media\LibraryIndex;
use App\Service\Media\MdblistClient;
use App\Service\Media\RadarrClient;
use App\Service\Media\SonarrClient;
use App\Service\ServiceInstanceProvider;
use Psr\Log\LoggerInterface;

/**
 * One calendar from three sources: Radarr, Sonarr and MDBList.
 *
 * The upstream Calendrier page answers "what that I already own is coming",
 * because it is fed by Radarr and Sonarr alone. MDBList, being Trakt-linked,
 * answers "what that I follow is coming", including titles that were never
 * added to Radarr or Sonarr. Measured on Mira's data over 45 days: 9 of the 10
 * shows MDBList knows about were invisible on the Calendrier page, and 4 of
 * the 5 Calendrier knows about were invisible to MDBList. Neither is a superset
 * of the other, which is why this merges rather than replaces.
 *
 * The event shape is deliberately the SAME shape CalendrierController builds,
 * so the calendar UI copied into discover/_tab_calendar.html.twig runs against
 * it unmodified, plus the few fields a media card needs.
 *
 * Deliberately a copy of that controller's fetching rather than a refactor of
 * it: CalendrierController is byte-identical to upstream and must stay that
 * way, per the fork's upstream-boundary rule.
 */
class CalendarFeed
{
    /** Matches the upstream page's window so the two agree on what is in range. */
    private const DAYS_AHEAD  = 90;
    private const DAYS_BEFORE = 90;

    /** MDBList caps its range at 120 days. */
    private const MDBLIST_DAYS = 90;

    public function __construct(
        private readonly RadarrClient           $radarr,
        private readonly SonarrClient           $sonarr,
        private readonly MdblistClient          $mdblist,
        private readonly ServiceInstanceProvider $instances,
        private readonly LibraryIndex           $libraryIndex,
        private readonly ConfigService          $config,
        private readonly LoggerInterface        $logger,
    ) {}

    /**
     * @return array{
     *   events: list<array<string, mixed>>,
     *   radarrFailed: bool,
     *   sonarrFailed: bool,
     *   mdblistFailed: bool,
     *   counts: array{film:int, episode:int, followed:int}
     * }
     */
    public function build(): array
    {
        $radarrFailed = $sonarrFailed = $mdblistFailed = false;

        $events = array_merge(
            $this->fromRadarr($radarrFailed),
            $this->fromSonarr($sonarrFailed),
        );

        // Everything already in the library, keyed the way the MDBList pass
        // needs to test against it.
        $owned = [];
        foreach ($events as $e) {
            if (!empty($e['tmdbId'])) {
                $owned[$e['cardType'] . ':' . (int) $e['tmdbId']] = true;
            }
        }

        $events = array_merge($events, $this->fromMdblist($owned, $mdblistFailed));

        usort($events, static fn (array $a, array $b): int => $a['sortDate'] <=> $b['sortDate']);

        $library = $this->library();
        foreach ($events as $i => $e) {
            $info = ($e['cardType'] ?? '') === 'movie'
                ? ($library['movie'][(int) ($e['tmdbId'] ?? 0)] ?? null)
                : ($library['tv']['tmdb_' . (int) ($e['tmdbId'] ?? 0)] ?? null);

            $events[$i]['in_library'] = $info !== null;
            $events[$i]['lib_status'] = $info['status'] ?? null;
            $events[$i]['lib_id']     = $info['id'] ?? null;

            $d = $events[$i]['date'] ?? $events[$i]['sortDate'] ?? null;
            if ($d instanceof \DateTimeInterface) {
                $d = $d->format('Y-m-d');
            }
            $events[$i]['dateStr'] = $d;
            unset($events[$i]['date']);
        }

        $counts = ['film' => 0, 'episode' => 0, 'followed' => 0];
        foreach ($events as $e) {
            if (($e['source'] ?? '') === 'mdblist') {
                $counts['followed']++;
            } elseif (($e['type'] ?? '') === 'film') {
                $counts['film']++;
            } else {
                $counts['episode']++;
            }
        }

        return [
            'events'        => $events,
            'radarrFailed'  => $radarrFailed,
            'sonarrFailed'  => $sonarrFailed,
            'mdblistFailed' => $mdblistFailed,
            'counts'        => $counts,
        ];
    }

    /** @return array{movie: array<int, mixed>, tv: array<string, mixed>} */
    private function library(): array
    {
        try {
            return $this->libraryIndex->build();
        } catch (\Throwable $e) {
            $this->logger->warning('Calendar library index failed', ['message' => $e->getMessage()]);

            return ['movie' => [], 'tv' => []];
        }
    }

    /** @return list<array<string, mixed>> */
    private function fromRadarr(bool &$failed): array
    {
        $out  = [];
        $seen = [];

        foreach ($this->instances->getEnabled(ServiceInstance::TYPE_RADARR) as $inst) {
            $client = $this->radarr->withInstance($inst);
            try {
                $movies = $client->getCalendar(self::DAYS_AHEAD, self::DAYS_BEFORE);
            } catch (\Throwable $e) {
                $failed = true;
                $this->logger->warning('Calendar radarr failed', ['instance' => $inst->getSlug(), 'message' => $e->getMessage()]);
                continue;
            }

            // getCalendar() returns [] both when nothing is scheduled and when
            // the call bailed, so the client's last error is what tells them
            // apart without throwing.
            if ($movies === [] && $client->getLastError() !== null) {
                $failed = true;
            }

            foreach ($movies as $m) {
                // The Radarr internal id differs per instance, so tmdbId is
                // the only stable dedup key across a 1080p + 4K pair.
                $dedupBase = 'r:' . ($m['tmdbId'] ?? (($m['title'] ?? '?') . '|' . ($m['year'] ?? '?')));

                $base = [
                    'type'      => 'film',
                    'source'    => 'library',
                    'title'     => $m['title'] ?? '—',
                    'year'      => $m['year'] ?? null,
                    'poster'    => $m['poster'] ?? null,
                    'fanart'    => $m['fanart'] ?? null,
                    'overview'  => $m['overview'] ?? null,
                    'status'    => $m['status'] ?? null,
                    'hasFile'   => (bool) ($m['hasFile'] ?? false),
                    'monitored' => (bool) ($m['monitored'] ?? false),
                    'id'        => $m['id'] ?? null,
                    'runtime'   => $m['runtime'] ?? null,
                    'studio'    => $m['studio'] ?? null,
                    'genres'    => $m['genres'] ?? [],
                    'certification' => $m['certification'] ?? null,
                    // Card fields. The upstream page does not carry these,
                    // which is why its rows cannot render as media cards.
                    'tmdbId'   => $m['tmdbId'] ?? null,
                    'cardType' => 'movie',
                    '_instanceSlug' => $inst->getSlug(),
                    '_instanceName' => $inst->getName(),
                ];

                $candidates = [];
                if ($m['inCinemasAt'] ?? null) { $candidates[] = ['date' => $m['inCinemasAt'], 'releaseType' => 'cinema']; }
                if ($m['digitalAt']   ?? null) { $candidates[] = ['date' => $m['digitalAt'],   'releaseType' => 'digital']; }
                if ($m['physicalAt']  ?? null) { $candidates[] = ['date' => $m['physicalAt'],  'releaseType' => 'physical']; }
                if ($candidates === []) { $candidates[] = ['date' => null, 'releaseType' => 'unknown']; }

                foreach ($candidates as $c) {
                    $d   = $c['date'] instanceof \DateTimeInterface ? $c['date']->format('Y-m-d') : 'none';
                    $key = $dedupBase . '|' . $c['releaseType'] . '|' . $d;
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;

                    $out[] = array_merge($base, $c, [
                        'sortDate' => $d === 'none' ? '9999-12-31' : $d,
                    ]);
                }
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function fromSonarr(bool &$failed): array
    {
        $out  = [];
        $seen = [];

        foreach ($this->instances->getEnabled(ServiceInstance::TYPE_SONARR) as $inst) {
            $client = $this->sonarr->withInstance($inst);
            try {
                $episodes = $client->getCalendar(self::DAYS_AHEAD, self::DAYS_BEFORE);
            } catch (\Throwable $e) {
                $failed = true;
                $this->logger->warning('Calendar sonarr failed', ['instance' => $inst->getSlug(), 'message' => $e->getMessage()]);
                continue;
            }

            if ($episodes === [] && $client->getLastError() !== null) {
                $failed = true;
            }

            foreach ($episodes as $e) {
                // tvdbId is the stable cross-instance key for a series, which
                // is why the upstream page dedups on it. It is NOT usable for
                // a media card, hence tmdbId being carried alongside.
                $key = 's:' . ($e['tvdbId'] ?? ($e['seriesTitle'] ?? '?'))
                     . '|S' . ($e['season'] ?? 0) . 'E' . ($e['episode'] ?? 0);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $d = $e['airDate'] ?? null;
                if ($d instanceof \DateTimeInterface) {
                    $d = $d->format('Y-m-d');
                }

                $out[] = [
                    'type'        => 'episode',
                    'source'      => 'library',
                    'seriesTitle' => $e['seriesTitle'] ?? '—',
                    'title'       => $e['title'] ?? '—',
                    'overview'    => $e['overview'] ?? null,
                    'season'      => $e['season'] ?? 0,
                    'episode'     => $e['episode'] ?? 0,
                    'date'        => $e['airDate'] ?? null,
                    'sortDate'    => $d ?? '9999-12-31',
                    'poster'      => $e['poster'] ?? null,
                    'fanart'      => $e['fanart'] ?? null,
                    'hasFile'     => (bool) ($e['hasFile'] ?? false),
                    'monitored'   => (bool) ($e['monitored'] ?? false),
                    'seriesId'    => $e['seriesId'] ?? null,
                    'runtime'     => $e['runtime'] ?? null,
                    'network'     => $e['network'] ?? null,
                    'genres'      => $e['genres'] ?? [],
                    'releaseType' => 'episode',
                    'tmdbId'      => $e['tmdbId'] ?? null,
                    'cardType'    => 'tv',
                    '_instanceSlug' => $inst->getSlug(),
                    '_instanceName' => $inst->getName(),
                ];
            }
        }

        return $out;
    }

    /**
     * The half the upstream page cannot see: releases for titles that are
     * followed but were never added to Radarr or Sonarr.
     *
     * @param  array<string, true> $owned already covered by the library pass
     * @return list<array<string, mixed>>
     */
    private function fromMdblist(array $owned, bool &$failed): array
    {
        if (!$this->config->has('mdblist_api_key')) {
            return [];
        }

        try {
            $today  = new \DateTimeImmutable('today');
            $events = $this->mdblist->getCalendar(
                $today->format('Y-m-d'),
                $today->modify('+' . self::MDBLIST_DAYS . ' days')->format('Y-m-d'),
                500,
            );
        } catch (\Throwable $e) {
            $failed = true;
            $this->logger->warning('Calendar mdblist failed', ['message' => $e->getMessage()]);

            return [];
        }

        $out = [];
        foreach ($events as $e) {
            $tmdbId = (int) ($e['id'] ?? 0);
            if ($tmdbId <= 0) {
                continue;
            }

            // Radarr and Sonarr already surfaced this one, with better data
            // (file status, monitoring). Showing it twice on the same day is
            // exactly the duplication this merge exists to avoid.
            $key = $e['type'] . ':' . $tmdbId;
            if (isset($owned[$key])) {
                continue;
            }

            $out[] = [
                'type'        => $e['type'] === 'movie' ? 'film' : 'episode',
                'source'      => 'mdblist',
                'seriesTitle' => $e['type'] === 'movie' ? null : $e['title'],
                'title'       => $e['title'],
                'overview'    => null,
                'season'      => $e['season_number'],
                'episode'     => $e['episode_number'],
                'sortDate'    => $e['date'] !== '' ? $e['date'] : '9999-12-31',
                'date'        => $e['date'] !== '' ? $e['date'] : null,
                'poster'      => $e['poster'],
                'fanart'      => null,
                // Not in the library, by definition of the dedup above.
                'hasFile'     => false,
                'monitored'   => false,
                'genres'      => [],
                'releaseType' => 'followed',
                'tmdbId'      => $tmdbId,
                'cardType'    => $e['type'],
                'episodeTitle' => $e['episode_title'] ?? null,
            ];
        }

        return $out;
    }
}
