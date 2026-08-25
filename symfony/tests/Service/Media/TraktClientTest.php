<?php

namespace App\Tests\Service\Media;

use App\Service\ConfigService;
use App\Service\Media\TraktClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Pure-logic coverage of TraktClient::mapPlayback(). No network: this is the
 * part of getPlayback() that can be wrong without a live /sync/playback call.
 *
 * Fixtures below are invented, not the account data used to probe the live
 * endpoint while building this feature.
 */
#[AllowMockObjectsWithoutExpectations]
class TraktClientTest extends TestCase
{
    private function makeClient(): TraktClient
    {
        $config = $this->createMock(ConfigService::class);

        return new TraktClient($config, $this->createMock(CacheInterface::class), new NullLogger());
    }

    private function mapPlayback(TraktClient $client, array $raw): array
    {
        $m = new ReflectionMethod($client, 'mapPlayback');
        $m->setAccessible(true);

        return $m->invoke($client, $raw);
    }

    private function traktUrlFromSearch(TraktClient $client, string $type, array $results): ?string
    {
        $m = new ReflectionMethod($client, 'traktUrlFromSearch');
        $m->setAccessible(true);

        return $m->invoke($client, $type, $results);
    }

    private function historyPayload(TraktClient $client, int $tmdbId, string $type): array
    {
        $m = new ReflectionMethod($client, 'historyPayload');
        $m->setAccessible(true);

        return $m->invoke($client, $tmdbId, $type);
    }

    private function mapLists(TraktClient $client, array $raw): array
    {
        $m = new ReflectionMethod($client, 'mapLists');
        $m->setAccessible(true);

        return $m->invoke($client, $raw);
    }

    /**
     * The card an episode's progress lands on is the SHOW's card, so the map
     * must be keyed on the show's tmdb id, never the episode's own tmdb id.
     */
    public function testEpisodeProgressIsKeyedOnTheShowTmdbIdNotTheEpisode(): void
    {
        $map = $this->mapPlayback($this->makeClient(), [[
            'type'     => 'episode',
            'progress' => 79.0,
            'paused_at' => '2026-05-14T09:36:54.000Z',
            'episode'  => ['season' => 1, 'number' => 7, 'ids' => ['trakt' => 111, 'tmdb' => 999999]],
            'show'     => ['title' => 'Fixture Show', 'year' => 2024, 'ids' => ['trakt' => 222, 'tmdb' => 12345]],
        ]]);

        $this->assertArrayHasKey('tv:12345', $map);
        $this->assertArrayNotHasKey('tv:999999', $map);
        $this->assertSame(79.0, $map['tv:12345']['progress']);
        $this->assertSame(1, $map['tv:12345']['season']);
        $this->assertSame(7, $map['tv:12345']['episode']);
        $this->assertSame('Fixture Show', $map['tv:12345']['title']);
        $this->assertSame(2024, $map['tv:12345']['year']);
    }

    public function testMovieProgressIsKeyedOnMovieTmdbIdWithNoSeasonOrEpisode(): void
    {
        $map = $this->mapPlayback($this->makeClient(), [[
            'type'      => 'movie',
            'progress'  => 42.75,
            'paused_at' => '2026-05-01T12:00:00.000Z',
            'movie'     => ['title' => 'Fixture Film', 'year' => 2025, 'ids' => ['trakt' => 1, 'tmdb' => 555]],
        ]]);

        $this->assertArrayHasKey('movie:555', $map);
        $this->assertSame(42.8, $map['movie:555']['progress']);
        $this->assertNull($map['movie:555']['season']);
        $this->assertNull($map['movie:555']['episode']);
        $this->assertSame('Fixture Film', $map['movie:555']['title']);
        $this->assertSame(2025, $map['movie:555']['year']);
    }

    /**
     * A media object with no title or year (should never happen against the
     * live API, but nothing here should ever throw over it) degrades to an
     * empty title and a null year rather than a missing array key.
     */
    public function testMissingTitleAndYearFallBackToEmptyStringAndNull(): void
    {
        $map = $this->mapPlayback($this->makeClient(), [[
            'type'     => 'movie',
            'progress' => 10.0,
            'movie'    => ['ids' => ['tmdb' => 777]],
        ]]);

        $this->assertSame('', $map['movie:777']['title']);
        $this->assertNull($map['movie:777']['year']);
    }

    /**
     * Several episodes of the same show can be mid-watch at once; the map
     * keeps only the one paused most recently.
     */
    public function testMostRecentlyPausedEpisodeWinsForTheSameShow(): void
    {
        $map = $this->mapPlayback($this->makeClient(), [
            [
                'type' => 'episode', 'progress' => 10.0, 'paused_at' => '2026-05-01T00:00:00.000Z',
                'episode' => ['season' => 1, 'number' => 1, 'ids' => ['tmdb' => 1]],
                'show'    => ['ids' => ['tmdb' => 12345]],
            ],
            [
                'type' => 'episode', 'progress' => 90.0, 'paused_at' => '2026-05-14T09:36:54.000Z',
                'episode' => ['season' => 1, 'number' => 7, 'ids' => ['tmdb' => 2]],
                'show'    => ['ids' => ['tmdb' => 12345]],
            ],
            [
                'type' => 'episode', 'progress' => 50.0, 'paused_at' => '2026-05-10T00:00:00.000Z',
                'episode' => ['season' => 1, 'number' => 4, 'ids' => ['tmdb' => 3]],
                'show'    => ['ids' => ['tmdb' => 12345]],
            ],
        ]);

        $this->assertCount(1, $map);
        $this->assertSame(90.0, $map['tv:12345']['progress']);
        $this->assertSame(7, $map['tv:12345']['episode']);
    }

    public function testEntriesWithoutATmdbIdAreDropped(): void
    {
        $map = $this->mapPlayback($this->makeClient(), [
            ['type' => 'movie', 'progress' => 10.0, 'movie' => ['ids' => ['imdb' => 'tt1']]],
            ['type' => 'movie', 'progress' => 10.0, 'movie' => ['ids' => ['tmdb' => 42]]],
        ]);

        $this->assertCount(1, $map);
        $this->assertArrayHasKey('movie:42', $map);
    }

    public function testUnknownEntryTypeIsIgnored(): void
    {
        $map = $this->mapPlayback($this->makeClient(), [
            ['type' => 'season', 'progress' => 10.0],
        ]);

        $this->assertSame([], $map);
    }

    /**
     * Prismarr's "tv" must be sent to Trakt's /search/tmdb as "show", and the
     * matching entry's slug must build a /shows/ URL, not /movies/.
     */
    public function testTvTypeMapsToShowAndBuildsShowsUrl(): void
    {
        $url = $this->traktUrlFromSearch($this->makeClient(), 'tv', [[
            'type' => 'show',
            'show' => ['ids' => ['slug' => 'fixture-show', 'tmdb' => 12345, 'trakt' => 222]],
        ]]);

        $this->assertSame('https://app.trakt.tv/shows/fixture-show', $url);
    }

    public function testMovieTypeBuildsMoviesUrl(): void
    {
        $url = $this->traktUrlFromSearch($this->makeClient(), 'movie', [[
            'type'  => 'movie',
            'movie' => ['ids' => ['slug' => 'fixture-film-2026', 'tmdb' => 555, 'trakt' => 1]],
        ]]);

        $this->assertSame('https://app.trakt.tv/movies/fixture-film-2026', $url);
    }

    public function testMissingSlugReturnsNull(): void
    {
        $url = $this->traktUrlFromSearch($this->makeClient(), 'movie', [[
            'type'  => 'movie',
            'movie' => ['ids' => ['tmdb' => 555, 'trakt' => 1]],
        ]]);

        $this->assertNull($url);
    }

    public function testEmptyResultsReturnNull(): void
    {
        $this->assertNull($this->traktUrlFromSearch($this->makeClient(), 'movie', []));
    }

    /**
     * Coverage of TraktClient::mapLists(), the row shape the kebab menu's
     * "Add to list" picker renders from GET /users/me/lists.
     *
     * Fixtures below are invented, not the account data used to probe the
     * live endpoint while building this feature.
     */
    public function testMapListsReadsIdNameSlugCountAndPrivacy(): void
    {
        $rows = $this->mapLists($this->makeClient(), [[
            'name'       => 'Fixture Watchlist A',
            'privacy'    => 'private',
            'item_count' => 4,
            'ids'        => ['slug' => 'fixture-watchlist-a', 'trakt' => 909090],
        ]]);

        $this->assertCount(1, $rows);
        $this->assertSame(909090, $rows[0]['id']);
        $this->assertSame('Fixture Watchlist A', $rows[0]['name']);
        $this->assertSame('fixture-watchlist-a', $rows[0]['slug']);
        $this->assertSame(4, $rows[0]['item_count']);
        $this->assertSame('private', $rows[0]['privacy']);
    }

    public function testMapListsDropsEntriesWithoutATraktId(): void
    {
        $rows = $this->mapLists($this->makeClient(), [
            ['name' => 'No id', 'ids' => ['slug' => 'no-id']],
            ['name' => 'Has id', 'ids' => ['slug' => 'has-id', 'trakt' => 1]],
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame('Has id', $rows[0]['name']);
    }

    public function testMapListsFallsBackToEmptyStringAndZeroCount(): void
    {
        $rows = $this->mapLists($this->makeClient(), [
            ['ids' => ['trakt' => 42]],
        ]);

        $this->assertSame('', $rows[0]['name']);
        $this->assertSame('', $rows[0]['slug']);
        $this->assertSame(0, $rows[0]['item_count']);
        $this->assertSame('', $rows[0]['privacy']);
    }

    public function testMapListsReturnsEmptyArrayForEmptyInput(): void
    {
        $this->assertSame([], $this->mapLists($this->makeClient(), []));
    }

    /**
     * A real pool rather than a mock: mergeRecentWrites() is guarded on
     * CacheItemPoolInterface, and ArrayAdapter satisfies both that and the
     * CacheInterface the constructor asks for.
     */
    private function makeClientWithPool(ArrayAdapter $pool, string $username = 'tester'): TraktClient
    {
        $client = new TraktClient($this->createMock(ConfigService::class), $pool, new NullLogger());

        // Normally set by ensureConfig() from settings; the cache key is built
        // from it, so it has to be present for the pool lookup to line up.
        $p = new ReflectionProperty($client, 'username');
        $p->setAccessible(true);
        $p->setValue($client, $username);

        return $client;
    }

    private function seedRecentWrites(ArrayAdapter $pool, array $writes, string $username = 'tester'): void
    {
        $item = $pool->getItem('prismarr_trakt_v2_' . sha1($username . '_recent_writes'));
        $item->set($writes);
        $pool->save($item);
    }

    private function mergeRecentWrites(TraktClient $client, array $map): array
    {
        $m = new ReflectionMethod($client, 'mergeRecentWrites');
        $m->setAccessible(true);

        return $m->invoke($client, $map);
    }

    /**
     * Trakt's reads lag a write by minutes, so a title marked seconds ago is
     * absent from the freshly fetched map. Without this overlay the rebuild
     * caches that gap for a full TTL and the watched badge never appears.
     */
    public function testRecentWriteFillsAGapTraktHasNotCaughtUpWith(): void
    {
        $pool = new ArrayAdapter();
        $this->seedRecentWrites($pool, ['movie:83533' => '2026-08-25T09:13:00.000Z']);

        $merged = $this->mergeRecentWrites($this->makeClientWithPool($pool), [
            'movie:19995' => ['plays' => 1, 'last_watched_at' => '2020-10-23T00:00:00.000Z'],
        ]);

        $this->assertArrayHasKey('movie:83533', $merged);
        $this->assertSame(1, $merged['movie:83533']['plays']);
        $this->assertSame('2026-08-25T09:13:00.000Z', $merged['movie:83533']['last_watched_at']);
        $this->assertArrayHasKey('movie:19995', $merged, 'the fetched entries must survive');
    }

    /**
     * Once Trakt has caught up it is the authority: it knows the real play
     * count, which the optimistic overlay can only ever guess at.
     */
    public function testTraktEntryWinsOverARememberedWrite(): void
    {
        $pool = new ArrayAdapter();
        $this->seedRecentWrites($pool, ['movie:83533' => '2026-08-25T09:13:00.000Z']);

        $merged = $this->mergeRecentWrites($this->makeClientWithPool($pool), [
            'movie:83533' => ['plays' => 4, 'last_watched_at' => '2026-08-25T09:13:00.000Z'],
        ]);

        $this->assertSame(4, $merged['movie:83533']['plays']);
    }

    public function testMergeIsAnIdentityWhenNothingWasRecentlyWritten(): void
    {
        $map = ['movie:19995' => ['plays' => 1, 'last_watched_at' => null]];

        $this->assertSame($map, $this->mergeRecentWrites($this->makeClientWithPool(new ArrayAdapter()), $map));
    }

    /**
     * Sending watched_at=released made Trakt stamp the entry with the air date,
     * so a successful mark sorted weeks down a history ordered by watched_at
     * and read as a failed write. Omitting the field lets Trakt stamp now.
     */
    public function testHistoryPayloadSendsNoWatchedAtSoTraktStampsNow(): void
    {
        $body = $this->historyPayload($this->makeClient(), 12345, 'movie');

        $this->assertArrayNotHasKey('watched_at', $body['movies'][0]);
        $this->assertSame(['ids' => ['tmdb' => 12345]], $body['movies'][0]);
    }

    public function testHistoryPayloadPutsMoviesAndShowsInTheirOwnBucket(): void
    {
        $client = $this->makeClient();

        $this->assertSame(
            ['movies' => [['ids' => ['tmdb' => 550]]]],
            $this->historyPayload($client, 550, 'movie')
        );
        $this->assertSame(
            ['shows' => [['ids' => ['tmdb' => 1399]]]],
            $this->historyPayload($client, 1399, 'tv')
        );
    }
}
