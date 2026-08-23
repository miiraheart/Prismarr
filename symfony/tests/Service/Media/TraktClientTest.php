<?php

namespace App\Tests\Service\Media;

use App\Service\ConfigService;
use App\Service\Media\TraktClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
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
}
