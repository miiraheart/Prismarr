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
        // Trakt and MDBList are configured too, so their sidebar entries WOULD
        // render. Without this, testTheSidebarHasOneDiscoverEntryNotThree
        // passes for the wrong reason: the entries are simply absent because
        // the services are unconfigured, not because they were collapsed.
        $em->persist(new Setting('trakt_client_id', 'test-client-id'));
        $em->persist(new Setting('mdblist_api_key', 'test-key'));
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

    public function testTheWatchlistsTabFragmentRendersOnItsOwn(): void
    {
        $this->client->request('GET', '/decouverte/tab/watchlists');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-dsc-pane="watchlists"]');
    }

    public function testTheOldTraktUrlRedirectsIntoTheWatchlistsTab(): void
    {
        $this->client->request('GET', '/trakt');

        $this->assertResponseRedirects('/decouverte?tab=watchlists');
    }

    public function testTheWatchlistsTabShipsRowsNotOneFlatGrid(): void
    {
        $this->client->request('GET', '/decouverte/tab/watchlists');

        $this->assertResponseIsSuccessful();
        // Rows, not one flat grid: the local Prismarr watchlist and the Trakt
        // watchlist each get their own, and Trakt custom lists add more.
        $this->assertSelectorExists('[data-wl-row="local"]');
        $this->assertSelectorExists('[data-wl-row="trakt"]');
    }

    /**
     * The modal partial defines window.renderCardHTML, and a server-rendered
     * tab's inline script runs while the document is parsed. With the modal
     * included AFTER the panes, the Trakt tab (now Watchlists) called
     * renderCardHTML before it existed, threw, and rendered zero cards while
     * its counts still showed 26 movies and 28 shows.
     */
    public function testTheSharedCardRendererIsDefinedBeforeAnyTabPane(): void
    {
        $this->client->request('GET', '/decouverte', ['tab' => 'watchlists']);

        $html     = (string) $this->client->getResponse()->getContent();
        $renderer = strpos($html, 'function renderCardHTML');
        $panes    = strpos($html, 'id="dsc-panes"');

        $this->assertNotFalse($renderer, 'renderCardHTML must be on the page.');
        $this->assertNotFalse($panes, 'The pane container must be on the page.');
        $this->assertLessThan(
            $panes,
            $renderer,
            'The modal partial must be included before the panes, or a tab script runs before renderCardHTML exists.',
        );
    }

    /**
     * A tab may ship a JSON data island next to its executable script. The
     * lazy-load path re-creates scripts so they run, and dropping the type or
     * id of a non-executable one leaves the tab reading an empty seed.
     */
    public function testTheWatchlistsSeedKeepsItsTypeAndId(): void
    {
        $this->client->request('GET', '/decouverte/tab/watchlists');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('script#wl-seed[type="application/json"]');
    }

    /**
     * Until this was added the only way to pin a list was to find it in the
     * MDBList directory and open it, so a list the directory does not surface
     * could not be added at all, even though /lists/pin accepts any supported
     * URL.
     */
    public function testTheListsTabCanPinAListByUrl(): void
    {
        $this->client->request('GET', '/decouverte/tab/lists');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('#dv-add-toggle');
        $this->assertSelectorExists('#dv-add-url');
        $this->assertSelectorExists('#dv-add-btn');
    }

    /**
     * The Lists toolbar must use the same language as the Discover tab: one
     * search field, then pill action buttons that toggle panels.
     */
    public function testTheListsToolbarUsesTheSharedSearchbarDesign(): void
    {
        $this->client->request('GET', '/decouverte/tab/lists');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('#dv-tabs.tmdb-searchbar');
        $this->assertSelectorExists('#dv-search.tmdb-search-input');
        $this->assertSelectorExists('#dv-filters-toggle.tmdb-action-btn');
        $this->assertSelectorExists('#dv-add-toggle.tmdb-action-btn');
    }

    public function testTheListsTabHasAFilterPanel(): void
    {
        $this->client->request('GET', '/decouverte/tab/lists');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('#dv-filters');
        $this->assertSelectorExists('#dv-year-min');
        $this->assertSelectorExists('#dv-year-max');
        $this->assertSelectorExists('#dv-vote-min');
        $this->assertSelectorExists('#dv-sort');
        $this->assertSelectorExists('#dv-filter-reset');
    }

    /**
     * The toolbar styles must come from the shell, not from a tab pane. Only
     * the active pane is server rendered, so a copy living inside the Discover
     * tab left the Lists toolbar unstyled whenever Lists was the tab that
     * loaded.
     */
    public function testToolbarStylesArePresentEvenWhenDiscoverIsNotTheActiveTab(): void
    {
        $this->client->request('GET', '/decouverte', ['tab' => 'lists']);

        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('.tmdb-searchbar', $html);
        $this->assertStringContainsString('.tmdb-action-btn', $html);
    }

    public function testTheSidebarHasOneDiscoverEntryNotThree(): void
    {
        $crawler = $this->client->request('GET', '/decouverte');

        $this->assertResponseIsSuccessful();
        $this->assertCount(
            1,
            $crawler->filter('.navbar-nav a.nav-link[href="/decouverte"]'),
            'Discover, Lists and Trakt should be one sidebar entry after the merge.',
        );
        $this->assertCount(0, $crawler->filter('.navbar-nav a.nav-link[href="/lists"]'));
        $this->assertCount(0, $crawler->filter('.navbar-nav a.nav-link[href="/trakt"]'));
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
