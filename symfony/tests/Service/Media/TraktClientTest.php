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
            'show'     => ['title' => 'Fixture Show', 'ids' => ['trakt' => 222, 'tmdb' => 12345]],
        ]]);

        $this->assertArrayHasKey('tv:12345', $map);
        $this->assertArrayNotHasKey('tv:999999', $map);
        $this->assertSame(79.0, $map['tv:12345']['progress']);
        $this->assertSame(1, $map['tv:12345']['season']);
        $this->assertSame(7, $map['tv:12345']['episode']);
    }

    public function testMovieProgressIsKeyedOnMovieTmdbIdWithNoSeasonOrEpisode(): void
    {
        $map = $this->mapPlayback($this->makeClient(), [[
            'type'      => 'movie',
            'progress'  => 42.75,
            'paused_at' => '2026-05-01T12:00:00.000Z',
            'movie'     => ['title' => 'Fixture Film', 'ids' => ['trakt' => 1, 'tmdb' => 555]],
        ]]);

        $this->assertArrayHasKey('movie:555', $map);
        $this->assertSame(42.8, $map['movie:555']['progress']);
        $this->assertNull($map['movie:555']['season']);
        $this->assertNull($map['movie:555']['episode']);
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
}
