<?php

namespace App\Tests\Controller;

use App\Tests\AbstractWebTestCase;

/**
 * MDBList is deliberately left unconfigured here: the page must still render,
 * with a "configure me" banner rather than a stack trace, exactly like every
 * other service page in this app.
 */
class DiscoverControllerTest extends AbstractWebTestCase
{
    public function testDiscoverPageRendersWithoutMdblistConfigured(): void
    {
        $this->client->request('GET', '/discover');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('#dv-tabs');
    }

    public function testListsFeedReturnsAnEmptyPayloadWhenUnconfigured(): void
    {
        $this->client->request('GET', '/discover/lists');

        $this->assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('lists', $payload);
        $this->assertSame([], $payload['lists']);
    }

    public function testListFeedRejectsAnUnsupportedSource(): void
    {
        $this->client->request('GET', '/discover/list', ['src' => 'https://example.com/not/a/list']);

        $this->assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame([], $payload['items']);
        $this->assertNotSame('', (string) ($payload['error'] ?? ''));
    }

    /**
     * The stored url is rebuilt from the parsed user/slug rather than kept
     * as submitted, so a query string or fragment smuggled through the pin
     * request cannot survive into the pinned-lists JSON rendered on /discover.
     */
    public function testPinStripsQueryStringAndFragmentFromTheStoredUrl(): void
    {
        $this->client->request(
            'POST',
            '/discover/pin',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'url'   => 'https://mdblist.com/lists/bob/my-list?x=</script><script>alert(1)</script>',
                'label' => 'My List',
            ]),
        );

        $this->assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($payload['ok']);
        $this->assertSame('https://mdblist.com/lists/bob/my-list', $payload['pinned'][0]['url']);
    }
}
