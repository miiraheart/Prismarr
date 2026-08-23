<?php

namespace App\Tests\Service\Media;

use App\Service\ConfigService;
use App\Service\Media\MdblistClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Pure-logic coverage of MdblistClient. No network: buildUrl() is the only
 * part of request() that can be wrong without a live call, and it is the part
 * that carries the credential.
 */
#[AllowMockObjectsWithoutExpectations]
class MdblistClientTest extends TestCase
{
    private function makeClient(string $key = 'testkey'): MdblistClient
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('get')->willReturnMap([
            ['mdblist_enabled', null],
            ['mdblist_api_key', $key],
        ]);
        $config->method('require')->willReturn($key);

        return new MdblistClient($config, $this->createMock(CacheInterface::class), new NullLogger());
    }

    private function buildUrl(MdblistClient $client, string $path, array $params): string
    {
        $m = new ReflectionMethod($client, 'buildUrl');
        $m->setAccessible(true);

        return $m->invoke($client, $path, $params);
    }

    /**
     * The OpenAPI schema declares the key as a QUERY parameter named `apikey`.
     * An X-API-Key header, which the first draft of the spec assumed, is ignored.
     */
    public function testApiKeyTravelsAsAQueryParameter(): void
    {
        $url = $this->buildUrl($this->makeClient('abc123'), '/lists/top', ['limit' => 60]);

        $this->assertStringContainsString('apikey=abc123', $url);
        $this->assertStringStartsWith('https://api.mdblist.com/lists/top?', $url);
        $this->assertStringContainsString('limit=60', $url);
    }

    public function testNullParametersAreDroppedRatherThanSentEmpty(): void
    {
        $url = $this->buildUrl($this->makeClient(), '/lists/top', ['cursor' => null, 'limit' => 100]);

        $this->assertStringNotContainsString('cursor', $url);
        $this->assertStringContainsString('limit=100', $url);
    }

    private function mapListRows(MdblistClient $client, array $raw): array
    {
        $m = new ReflectionMethod($client, 'mapListRows');
        $m->setAccessible(true);

        return $m->invoke($client, $raw);
    }

    public function testListRowsAreMappedToPrismarrVocabulary(): void
    {
        $rows = $this->mapListRows($this->makeClient(), [
            [
                'id' => 4231, 'name' => 'Top Horror 2025', 'slug' => 'top-horror-2025',
                'user_name' => 'garycrawfordgc', 'items' => 212, 'likes' => 88, 'mediatype' => 'movie',
            ],
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame(4231, $rows[0]['id']);
        $this->assertSame('Top Horror 2025', $rows[0]['name']);
        $this->assertSame('garycrawfordgc', $rows[0]['user']);
        $this->assertSame(212, $rows[0]['items']);
        $this->assertSame('https://mdblist.com/lists/garycrawfordgc/top-horror-2025', $rows[0]['url']);
    }

    public function testListRowsWithoutASlugAreDropped(): void
    {
        // A row with no user or slug cannot be opened or pinned, so it is not
        // worth rendering: the card would be a dead end.
        $rows = $this->mapListRows($this->makeClient(), [
            ['id' => 1, 'name' => 'Broken', 'items' => 5],
            ['id' => 2, 'name' => 'Fine', 'slug' => 'fine', 'user_name' => 'bob', 'items' => 5],
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]['id']);
    }

    private function mapItems(MdblistClient $client, array $payload): array
    {
        $m = new ReflectionMethod($client, 'mapItems');
        $m->setAccessible(true);

        return $m->invoke($client, $payload);
    }

    /**
     * MDBList splits its answer into `movies` and `shows`. Prismarr says
     * "tv" where MDBList says "show", the same normalisation TraktClient does.
     */
    public function testMoviesAndShowsAreFlattenedAndTypeNormalised(): void
    {
        $items = $this->mapItems($this->makeClient(), [
            'movies' => [
                ['id' => 1, 'title' => 'Longlegs', 'release_year' => 2024, 'imdb_id' => 'tt23468450', 'ids' => ['tmdb' => 976893]],
            ],
            'shows' => [
                ['id' => 2, 'title' => 'Severance', 'release_year' => 2022, 'imdb_id' => 'tt11280740', 'ids' => ['tmdb' => 95396]],
            ],
        ]);

        $this->assertCount(2, $items);
        $this->assertSame(976893, $items[0]['id']);
        $this->assertSame('movie', $items[0]['type']);
        $this->assertSame(2024, $items[0]['year']);
        $this->assertSame(95396, $items[1]['id']);
        $this->assertSame('tv', $items[1]['type']);
    }

    /**
     * Everything downstream (Prismarr, Radarr, Sonarr, the detail modal) is
     * keyed on a TMDB id. An entry without one has nothing to join against.
     */
    public function testEntriesWithoutATmdbIdAreDropped(): void
    {
        $items = $this->mapItems($this->makeClient(), [
            'movies' => [
                ['id' => 1, 'title' => 'Ghost', 'release_year' => 1990, 'ids' => ['imdb' => 'tt0099653']],
                ['id' => 2, 'title' => 'Real', 'release_year' => 1991, 'ids' => ['tmdb' => 42]],
            ],
        ]);

        $this->assertCount(1, $items);
        $this->assertSame(42, $items[0]['id']);
    }

    public function testCardFieldsDefaultToNullSoTheSharedRendererNeverBranches(): void
    {
        $items = $this->mapItems($this->makeClient(), [
            'movies' => [['id' => 1, 'title' => 'X', 'release_year' => 2022, 'ids' => ['tmdb' => 7]]],
        ]);

        $this->assertArrayHasKey('poster', $items[0]);
        $this->assertNull($items[0]['poster']);
        $this->assertNull($items[0]['vote']);
        $this->assertFalse($items[0]['in_library']);
        $this->assertNull($items[0]['lib_status']);
    }

    /**
     * Measured against the live API on 2026-08-23: `ratings` is an ARRAY of
     * {source, value, score, votes}, and the scales differ per source. imdb
     * value is 0 to 10, tmdb value is 0 to 100. The shared card badge wants
     * TMDb's native 0-to-10 scale.
     */
    public function testVoteComesFromTheRatingsArrayOnATenPointScale(): void
    {
        $items = $this->mapItems($this->makeClient(), [
            'shows' => [[
                'id' => 95350, 'title' => 'Lanterns', 'release_year' => 2026,
                'ids' => ['tmdb' => 95350],
                'ratings' => [
                    ['source' => 'imdb',    'value' => 8.0, 'score' => 80, 'votes' => 8919],
                    ['source' => 'tmdb',    'value' => 82,  'score' => 82, 'votes' => 141],
                    ['source' => 'mdblist', 'value' => null, 'score' => 82, 'votes' => null],
                ],
            ]],
        ]);

        $this->assertSame(8.0, $items[0]['vote']);
    }

    public function testVoteFallsBackToTmdbScoreDividedByTen(): void
    {
        $items = $this->mapItems($this->makeClient(), [
            'movies' => [[
                'id' => 1, 'title' => 'X', 'release_year' => 2024,
                'ids' => ['tmdb' => 7],
                'ratings' => [['source' => 'tmdb', 'value' => 63, 'score' => 63, 'votes' => 6]],
            ]],
        ]);

        $this->assertSame(6.3, $items[0]['vote']);
    }

    /** Posters arrive absolute; only the size segment is rewritten. */
    public function testPosterIsUpscaledFromW200ToW342(): void
    {
        $items = $this->mapItems($this->makeClient(), [
            'movies' => [[
                'id' => 1, 'title' => 'X', 'release_year' => 2024,
                'ids' => ['tmdb' => 7],
                'poster' => 'https://image.tmdb.org/t/p/w200/abc.jpg',
            ]],
        ]);

        $this->assertSame('https://image.tmdb.org/t/p/w342/abc.jpg', $items[0]['poster']);
    }

    private function cursorFrom(MdblistClient $client, ?array $data): ?string
    {
        $m = new ReflectionMethod($client, 'cursorFrom');
        $m->setAccessible(true);

        return $m->invoke($client, $data);
    }

    public function testCursorIsReadFromTheNestedPaginationBlock(): void
    {
        $cursor = $this->cursorFrom($this->makeClient(), [
            'pagination' => ['next_cursor' => 'eyJzIjoxMH0='],
        ]);

        $this->assertSame('eyJzIjoxMH0=', $cursor);
    }

    public function testCursorIsNullWhenPaginationHasNoNextCursor(): void
    {
        $cursor = $this->cursorFrom($this->makeClient(), [
            'pagination' => ['limit' => 100, 'offset' => 0, 'total' => 40, 'has_more' => false],
        ]);

        $this->assertNull($cursor);
    }

    /**
     * The regression this fixes: reading `next_cursor` from the top level,
     * where the real payload never puts it, silently stopped paging dead
     * after the first page.
     */
    public function testCursorIsNullWhenOnlyPresentAtTheTopLevel(): void
    {
        $cursor = $this->cursorFrom($this->makeClient(), [
            'next_cursor' => 'eyJzIjoxMH0=',
        ]);

        $this->assertNull($cursor);
    }

    public function testCursorIsNullForANullPayload(): void
    {
        $this->assertNull($this->cursorFrom($this->makeClient(), null));
    }
}
