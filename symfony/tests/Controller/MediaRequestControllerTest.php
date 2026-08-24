<?php

namespace App\Tests\Controller;

use App\Entity\Setting;
use App\Tests\AbstractWebTestCase;

/**
 * Adds from a browse page create a Seerr request rather than pushing straight
 * to Radarr or Sonarr.
 *
 * The Radarr and Sonarr pages keep adding directly. That split is enforced by
 * construction rather than by configuration: only the browse pages render
 * `.tmdb-add-btn`, and only that class triggers this endpoint.
 */
class MediaRequestControllerTest extends AbstractWebTestCase
{
    private function post(array $payload): void
    {
        $this->client->request(
            'POST',
            '/media-request',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload),
        );
    }

    /**
     * Without Seerr the caller is told to fall back to the direct quick-add,
     * NOT given an error. Upstream issue #87 asks for this explicitly, and it
     * is what stops the Add button going dead for a setup with no Seerr.
     */
    public function testItAsksForTheDirectFallbackWhenSeerrIsNotConfigured(): void
    {
        $this->post(['tmdb_id' => 603, 'type' => 'movie']);

        $this->assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertFalse($payload['ok']);
        $this->assertTrue($payload['fallback']);
    }

    public function testItRejectsAMissingTmdbId(): void
    {
        $this->configureSeerr();

        $this->post(['type' => 'movie']);

        $this->assertResponseStatusCodeSame(400);
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertFalse($payload['ok']);
    }

    public function testItRejectsAnUnknownMediaType(): void
    {
        $this->configureSeerr();

        $this->post(['tmdb_id' => 603, 'type' => 'album']);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testItIsPostOnly(): void
    {
        $this->client->request('GET', '/media-request');

        $this->assertResponseStatusCodeSame(405);
    }

    /**
     * With Seerr configured but unreachable in the test environment, the
     * endpoint must fail loudly with 502 rather than silently reporting
     * success. A silent success would make a broken Seerr look like a working
     * one, which is the failure mode worth guarding.
     */
    public function testItReportsUpstreamFailureRatherThanFakingSuccess(): void
    {
        $this->configureSeerr();

        $this->post(['tmdb_id' => 603, 'type' => 'movie']);

        $this->assertResponseStatusCodeSame(502);
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertFalse($payload['ok']);
        $this->assertNotSame('', (string) ($payload['error'] ?? ''));
    }

    private function configureSeerr(): void
    {
        $em = $this->em();
        $em->persist(new Setting('jellyseerr_api_key', 'test-key'));
        $em->persist(new Setting('jellyseerr_url', 'http://seerr.invalid:5055'));
        $em->flush();
    }
}
