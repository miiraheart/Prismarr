<?php

namespace App\Tests\Controller;

use App\Entity\Setting;
use App\Tests\AbstractWebTestCase;

/**
 * The merged Discover page.
 *
 * The first test is the important one. This page works by having a fork route
 * shadow the upstream `tmdb_index` route on the same path, by priority. That
 * is subtle and silent when it breaks: if upstream ever adds its own priority
 * to tmdb_index, /decouverte quietly starts rendering the old page again. This
 * test failing after a nightly sync is the intended early warning.
 */
class DiscoverPageControllerTest extends AbstractWebTestCase
{
    /**
     * The page needs a TMDb key present, otherwise the controller's own
     * wizard bounce fires and every assertion here sees a 302 instead of the
     * page. testItBouncesToTheWizardWhenTmdbIsNotConfigured covers the
     * unconfigured case deliberately, by deleting this row again.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $em = $this->em();
        $em->persist(new Setting('tmdb_api_key', 'test-key'));
        $em->flush();
    }

    public function testDecouverteResolvesToTheForkRouteNotUpstream(): void
    {
        $this->client->request('GET', '/decouverte');

        $this->assertResponseIsSuccessful();
        $this->assertSame(
            'discover_page',
            $this->client->getRequest()->attributes->get('_route'),
            'The fork route must shadow upstream tmdb_index on /decouverte.',
        );
    }

    public function testThePageRendersTheTabBarWithDiscoverActive(): void
    {
        $this->client->request('GET', '/decouverte');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('#dsc-tabs');
        $this->assertSelectorExists('[data-dsc-pane="discover"]');
    }

    public function testAnUnknownTabFallsBackToDiscoverRatherThanErroring(): void
    {
        $this->client->request('GET', '/decouverte', ['tab' => 'nonsense']);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-dsc-pane="discover"]');
    }

    public function testTheDiscoverTabFragmentRendersOnItsOwn(): void
    {
        $this->client->request('GET', '/decouverte/tab/discover');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-dsc-pane="discover"]');
        $this->assertSelectorNotExists('#dsc-tabs');
    }

    public function testAnUnknownTabFragmentIs404(): void
    {
        $this->client->request('GET', '/decouverte/tab/nonsense');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testTheListsTabFragmentRendersOnItsOwn(): void
    {
        $this->client->request('GET', '/decouverte/tab/lists');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-dsc-pane="lists"]');
    }

    public function testTheListsTabCanBeRequestedAsTheActiveTab(): void
    {
        $this->client->request('GET', '/decouverte', ['tab' => 'lists']);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('#dsc-tabs');
        $this->assertSelectorExists('[data-dsc-pane="lists"]');
    }

    public function testTheOldListsUrlRedirectsIntoTheListsTab(): void
    {
        $this->client->request('GET', '/lists');

        $this->assertResponseRedirects('/decouverte?tab=lists');
    }

    public function testTheTraktTabFragmentRendersOnItsOwn(): void
    {
        $this->client->request('GET', '/decouverte/tab/trakt');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-dsc-pane="trakt"]');
    }

    public function testTheOldTraktUrlRedirectsIntoTheTraktTab(): void
    {
        $this->client->request('GET', '/trakt');

        $this->assertResponseRedirects('/decouverte?tab=trakt');
    }

    public function testTheTraktTabShipsTheSharedCardGridNotBespokeMarkup(): void
    {
        $this->client->request('GET', '/decouverte/tab/trakt');

        $this->assertResponseIsSuccessful();
        // The shared renderer fills this grid client-side. Its presence is
        // what distinguishes the reworked tab from the old server-rendered
        // bespoke cards, which emitted .media-card directly in Twig.
        $this->assertSelectorExists('#tk-grid[data-tk-shared="1"]');
    }

    /**
     * The page route deliberately does NOT start with `tmdb_`.
     *
     * ServiceRouteGuardSubscriber (upstream, un-editable) redirects a
     * configured-but-unhealthy service to its rule's `index` route, and skips
     * that only when the CURRENT route name equals that index. Upstream's own
     * page is safe because it IS `tmdb_index`. A fork route named `tmdb_*`
     * shadowing the same path would not be: TMDb going down would redirect to
     * `tmdb_index`, whose URL is /decouverte, which resolves back to the fork
     * route, which redirects again. An infinite loop, triggered by nothing
     * more than TMDb having a bad day.
     *
     * Keeping the name outside the `tmdb_` prefix makes the guard ignore this
     * route entirely, so the controller does the wizard bounce itself.
     */
    public function testThePageRouteIsOutsideTheTmdbGuardPrefix(): void
    {
        $this->client->request('GET', '/decouverte');

        $route = (string) $this->client->getRequest()->attributes->get('_route');
        $this->assertStringStartsNotWith(
            'tmdb_',
            $route,
            'A tmdb_-prefixed name here reintroduces the redirect loop when TMDb is unhealthy.',
        );
    }

    public function testItBouncesToTheWizardWhenTmdbIsNotConfigured(): void
    {
        $em  = $this->em();
        $row = $em->getRepository(Setting::class)->find('tmdb_api_key');
        if ($row !== null) {
            $em->remove($row);
            $em->flush();
        }

        $this->client->request('GET', '/decouverte');

        $this->assertResponseRedirects();
        $this->assertStringContainsString(
            'setup',
            (string) $this->client->getResponse()->headers->get('Location'),
        );
    }
}
