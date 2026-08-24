<?php

namespace App\Controller;

use App\Service\ConfigService;
use App\Service\Media\Discover\ListsTabContext;
use App\Service\Media\LibraryIndex;
use App\Service\Media\TmdbClient;
use App\Service\Media\TmdbEnricher;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The merged Discover page: Discover, Lists and Trakt as three tabs.
 *
 * ── Why this route shadows an upstream one ───────────────────────────────
 * `/decouverte` is upstream's TmdbController::index (route `tmdb_index`). It
 * is also this app's landing page, and it is referenced from eleven places,
 * nearly all upstream owned: HomeController's landing redirect,
 * ServiceRouteGuardSubscriber, two DashboardController deep links, the navbar
 * brand and five templates.
 *
 * Redirecting would therefore put a 302 on the landing page and on every
 * `?detail=` deep link. Instead this route claims the same path at a higher
 * priority, so `path('tmdb_index')` still generates `/decouverte` and that URL
 * now resolves here, with no redirect anywhere. Upstream's action is left
 * untouched and simply becomes unreachable, so it keeps fast-forward syncing.
 * Upstream itself uses this technique: see LegacyMediaRedirectController.
 *
 * ── Why the route name must NOT start with `tmdb_` ───────────────────────
 * ServiceRouteGuardSubscriber is upstream owned and matches by route NAME
 * PREFIX. Its second rule redirects a configured-but-unhealthy service to the
 * rule's `index` route, and skips that only when the current route name
 * EQUALS that index. Upstream's page is safe because it is `tmdb_index`.
 *
 * A fork route named `tmdb_*` on this same path would not be: TMDb going down
 * would redirect to `tmdb_index`, whose URL is `/decouverte`, which resolves
 * back to this controller, which redirects again. An infinite loop triggered
 * by nothing worse than TMDb having a bad day.
 *
 * So the name sits outside that prefix, the guard ignores this route, and the
 * wizard bounce it would have done is done explicitly in index() below.
 * DiscoverPageControllerTest::testThePageRouteIsOutsideTheTmdbGuardPrefix
 * pins this; do not "tidy" the route name.
 *
 * The shadowing itself is pinned by
 * DiscoverPageControllerTest::testDecouverteResolvesToTheForkRouteNotUpstream.
 * If upstream ever adds its own priority to tmdb_index, that test failing is
 * the early warning.
 */
#[IsGranted('ROLE_USER')]
class DiscoverPageController extends AbstractController
{
    /** Tab ids, in display order. Also the allowed values of ?tab= and {tab}. */
    public const TABS = ['discover', 'lists', 'trakt'];

    public function __construct(
        private readonly TmdbClient      $tmdb,
        private readonly LibraryIndex    $libraryIndex,
        private readonly TmdbEnricher    $enricher,
        private readonly ConfigService   $config,
        private readonly LoggerInterface $logger,
        private readonly ListsTabContext $listsContext,
    ) {}

    #[Route('/decouverte', name: 'discover_page', priority: 10)]
    public function index(Request $request): Response
    {
        // The bounce ServiceRouteGuardSubscriber would have done for a
        // `tmdb_`-named route. See the class docblock for why this route is
        // deliberately outside that prefix.
        if (!$this->config->has('tmdb_api_key')) {
            return $this->redirectToRoute('app_setup_tmdb');
        }

        $active = (string) $request->query->get('tab', 'discover');
        if (!in_array($active, self::TABS, true)) {
            $active = 'discover';
        }

        return $this->render('discover/index.html.twig', [
            'tabs'          => self::TABS,
            'activeTab'     => $active,
            'activeTabHtml' => $this->renderTab($active)->getContent(),
        ]);
    }

    /**
     * One tab as a bare HTML fragment, for the lazy load in the shell.
     *
     * `tab` cannot collide with upstream's routes under this prefix: it owns
     * section, filter, genres, resolve, detail, search, explorer, collection,
     * person, watchlist and mes-recommandations. `tab` is free.
     */
    #[Route('/decouverte/tab/{tab}', name: 'discover_page_tab', requirements: ['tab' => 'discover|lists|trakt'], priority: 10)]
    public function tab(string $tab): Response
    {
        return $this->renderTab($tab);
    }

    private function renderTab(string $tab): Response
    {
        return match ($tab) {
            'discover' => $this->renderDiscoverTab(),
            'lists'    => $this->renderListsTab(),
            default    => new Response('', Response::HTTP_NOT_FOUND),
        };
    }

    /**
     * The Lists tab. The row building lives in ListsTabContext, which
     * DiscoverController also uses for its /lists/* JSON routes, so there is
     * exactly one implementation of it.
     */
    private function renderListsTab(): Response
    {
        return $this->render('discover/_tab_lists.html.twig', $this->listsContext->build());
    }

    /**
     * The rows the Discover tab paints on arrival. Mirrors upstream
     * TmdbController::index so the tab is the page it replaces, not a thinner
     * version of it.
     */
    private function renderDiscoverTab(): Response
    {
        $error = false;
        $trending = $trendingMovies = $trendingTv = [];
        $popMovies = $popTv = $upcoming = $onAir = $topMovies = $topTv = [];

        try {
            $library = $this->libraryIndex->build();

            $trendingRaw = $this->tmdb->getTrendingAll('week');
            if ($trendingRaw === null || ($trendingRaw['results'] ?? null) === null) {
                $error = true;
            } else {
                $trending       = $this->enricher->enrich($trendingRaw['results'] ?? [], $library);
                $trendingMovies = $this->enricher->enrich($this->tmdb->getTrendingMovies('week')['results'] ?? [], $library, 'movie');
                $trendingTv     = $this->enricher->enrich($this->tmdb->getTrendingTv('week')['results'] ?? [],     $library, 'tv');
                $popMovies      = $this->enricher->enrich($this->tmdb->getPopularMovies()['results'] ?? [],        $library, 'movie');
                $popTv          = $this->enricher->enrich($this->tmdb->getPopularTv()['results'] ?? [],            $library, 'tv');
                $upcoming       = $this->enricher->enrich($this->tmdb->getUpcomingMovies()['results'] ?? [],       $library, 'movie');
                $onAir          = $this->enricher->enrich($this->tmdb->getOnTheAirTv()['results'] ?? [],           $library, 'tv');
                $topMovies      = $this->enricher->enrich($this->tmdb->getTopRatedMovies()['results'] ?? [],       $library, 'movie');
                $topTv          = $this->enricher->enrich($this->tmdb->getTopRatedTv()['results'] ?? [],           $library, 'tv');
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Discover tab failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $error = true;
        }

        return $this->render('discover/_tab_discover.html.twig', [
            'heroMovie'   => $trendingMovies[0] ?? null,
            'heroTv'      => $trendingTv[0] ?? null,
            'trending'    => $trending,
            'popMovies'   => $popMovies,
            'popTv'       => $popTv,
            'upcoming'    => $upcoming,
            'onAir'       => $onAir,
            'topMovies'   => $topMovies,
            'topTv'       => $topTv,
            'error'       => $error,
            'service_url' => $this->config->get('tmdb_api_key') !== null ? 'api.themoviedb.org' : null,
        ]);
    }
}
