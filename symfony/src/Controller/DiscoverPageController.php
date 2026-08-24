<?php

namespace App\Controller;

use App\Service\ConfigService;
use App\Service\Media\Discover\ListsTabContext;
use App\Service\Media\LibraryIndex;
use App\Service\Media\TmdbClient;
use App\Repository\Media\WatchlistItemRepository;
use App\Service\Media\TraktClient;
use App\Service\Media\TmdbEnricher;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

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
    public const TABS = ['discover', 'lists', 'watchlists'];

    /**
     * Tab ids that used to exist, mapped to their replacement.
     *
     * The Trakt tab became Watchlists once it grew a local-watchlist row and a
     * row per Trakt list. Old links, the /trakt redirect and any bookmark of
     * ?tab=trakt keep working rather than silently falling back to Discover.
     */
    private const TAB_ALIASES = ['trakt' => 'watchlists'];

    public function __construct(
        private readonly TmdbClient      $tmdb,
        private readonly LibraryIndex    $libraryIndex,
        private readonly TmdbEnricher    $enricher,
        private readonly ConfigService   $config,
        private readonly LoggerInterface $logger,
        private readonly ListsTabContext $listsContext,
        private readonly TraktClient     $trakt,
        private readonly WatchlistItemRepository $watchlistRepo,
        private readonly TranslatorInterface $translator,
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

        $active = self::TAB_ALIASES[(string) $request->query->get('tab', 'discover')]
            ?? (string) $request->query->get('tab', 'discover');
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
    #[Route('/decouverte/tab/{tab}', name: 'discover_page_tab', requirements: ['tab' => 'discover|lists|watchlists|trakt'], priority: 10)]
    public function tab(string $tab): Response
    {
        return $this->renderTab(self::TAB_ALIASES[$tab] ?? $tab);
    }

    private function renderTab(string $tab): Response
    {
        return match ($tab) {
            'discover' => $this->renderDiscoverTab(),
            'lists'    => $this->renderListsTab(),
            'watchlists' => $this->renderWatchlistsTab(),
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
     * The Watchlists tab: the local Prismarr watchlist, the Trakt watchlist,
     * and one row per Trakt custom list.
     *
     * Only the first two rows carry their items. The custom lists ship as row
     * shells and are filled by the template on scroll, because each one is its
     * own Trakt round trip and a user with a dozen lists would otherwise pay
     * for all of them on every page view. Same reasoning as the Lists tab.
     */
    private function renderWatchlistsTab(): Response
    {
        $error = false;

        try {
            $library = $this->libraryIndex->build();
        } catch (\Throwable $e) {
            $this->logger->warning('Watchlists library index failed', ['message' => $e->getMessage()]);
            $library = ['movie' => [], 'tv' => []];
        }

        try {
            $traktItems = $this->trakt->getWatchlist();
        } catch (\Throwable $e) {
            $this->logger->warning('Watchlists trakt watchlist failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $traktItems = [];
            $error      = true;
        }

        try {
            $lists = $this->trakt->getLists();
        } catch (\Throwable $e) {
            $this->logger->warning('Watchlists trakt lists failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $lists = [];
        }

        $rows = [[
            'key'   => 'local',
            'label' => $this->translator->trans('watchlists.row.local'),
            'items' => $this->enrichRows($this->localWatchlistRows(), $library),
            'lazy'  => false,
        ], [
            'key'   => 'trakt',
            'label' => $this->translator->trans('watchlists.row.trakt'),
            'items' => $this->enrichRows($this->sortByListedAt($traktItems), $library),
            'lazy'  => false,
        ]];

        foreach ($lists as $list) {
            // An empty list would render as a permanently empty row.
            if ((int) ($list['item_count'] ?? 0) === 0) {
                continue;
            }
            $rows[] = [
                'key'   => 'list-' . $list['id'],
                'label' => $list['name'],
                'ref'   => (string) $list['id'],
                'count' => (int) $list['item_count'],
                'items' => [],
                'lazy'  => true,
            ];
        }

        return $this->render('discover/_tab_watchlists.html.twig', [
            'rows'      => $rows,
            'error'     => $error,
            'can_write' => $this->trakt->hasWriteAccess(),
            'connected' => $this->trakt->hasWriteAccess() || $traktItems !== [],
        ]);
    }

    /**
     * The local Prismarr watchlist in the shared row shape.
     *
     * @return list<array<string, mixed>>
     */
    private function localWatchlistRows(): array
    {
        $rows = [];
        foreach ($this->watchlistRepo->findAllOrdered() as $item) {
            $rows[] = [
                'tmdb_id' => $item->getTmdbId(),
                'type'    => $item->getMediaType(),
                'title'   => $item->getTitle(),
                'year'    => $item->getYear(),
                'poster'  => TmdbClient::posterUrl($item->getPosterPath(), 'w342'),
                'vote'    => $item->getVote(),
            ];
        }

        return $rows;
    }

    /**
     * Badge rows with Radarr/Sonarr status, so the cards carry the Available
     * badge the standalone Trakt page never had.
     *
     * @param  list<array<string, mixed>> $rows
     * @param  array{movie: array<int, mixed>, tv: array<string, mixed>} $library
     * @return list<array<string, mixed>>
     */
    private function enrichRows(array $rows, array $library): array
    {
        foreach ($rows as $i => $row) {
            $info = $row['type'] === 'movie'
                ? ($library['movie'][(int) $row['tmdb_id']] ?? null)
                : ($library['tv']['tmdb_' . (int) $row['tmdb_id']] ?? null);

            $rows[$i] += [
                'in_library' => $info !== null,
                'lib_status' => $info['status'] ?? null,
                'lib_id'     => $info['id'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function sortByListedAt(array $rows): array
    {
        // Newest listed first, the order the Trakt site itself uses.
        usort($rows, static fn (array $a, array $b): int => ($b['listed_at'] ?? '') <=> ($a['listed_at'] ?? ''));

        return $rows;
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
