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
}
