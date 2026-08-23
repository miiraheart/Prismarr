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
}
