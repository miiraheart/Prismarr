<?php

namespace App\Controller;

use App\Service\ConfigService;
use App\Service\Media\Discover\ListSourceResolver;
use App\Service\Media\LibraryIndex;
use App\Service\Media\MdblistClient;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Discover v2, phase 1: the Lists tab.
 *
 * A new page rather than an extension of /decouverte, which is an upstream
 * file that already diverges after the detail-modal extraction. Everything
 * here reads through MdblistClient's cache: no entities, no migrations, and
 * pinned lists live in a single `setting` row as JSON.
 */
#[IsGranted('ROLE_USER')]
#[Route('/discover', name: 'discover_')]
class DiscoverController extends AbstractController
{
    private const PINNED_KEY = 'discover_pinned_lists';

    public function __construct(
        private readonly MdblistClient  $mdblist,
        private readonly LibraryIndex   $libraryIndex,
        private readonly ConfigService  $config,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('', name: 'index')]
    public function index(): Response
    {
        return $this->render('discover/index.html.twig', [
            'pinned'     => $this->pinned(),
            'configured' => $this->config->has('mdblist_api_key'),
        ]);
    }

    /** The Lists tab default view: the MDBList toplists directory, or a search over it. */
    #[Route('/lists', name: 'lists', methods: ['GET'])]
    public function lists(Request $request): JsonResponse
    {
        $query = trim((string) $request->query->get('q', ''));

        try {
            $lists = $query === ''
                ? $this->mdblist->getTopLists()
                : $this->mdblist->searchLists($query);
        } catch (\Throwable $e) {
            $this->logger->warning('Discover lists failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $lists = [];
        }

        return $this->json(['lists' => $lists, 'pinned' => $this->pinned()]);
    }

    /** One list, as a page of cards. */
    #[Route('/list', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $src    = (string) $request->query->get('src', '');
        $cursor = $request->query->get('cursor');
        $cursor = is_string($cursor) && $cursor !== '' ? $cursor : null;

        $parsed = ListSourceResolver::parse($src);
        if ($parsed === null) {
            return $this->json([
                'items'       => [],
                'next_cursor' => null,
                'error'       => 'unsupported_source',
            ]);
        }

        try {
            $page = $this->mdblist->getListItems($parsed['user'], $parsed['slug'], $cursor);
        } catch (\Throwable $e) {
            $this->logger->warning('Discover list failed', ['src' => $src, 'message' => $e->getMessage()]);

            return $this->json(['items' => [], 'next_cursor' => null, 'error' => 'unavailable']);
        }

        return $this->json([
            'items'       => $this->withLibraryStatus($page['items']),
            'next_cursor' => $page['next_cursor'],
            'total'       => $page['total'],
            'error'       => null,
        ]);
    }

    /**
     * Badge the titles Radarr or Sonarr already owns. The spec's rule is badge
     * everything and hide nothing, so a watched or owned title still renders.
     *
     * @param  list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function withLibraryStatus(array $items): array
    {
        try {
            $library = $this->libraryIndex->build();
        } catch (\Throwable $e) {
            $this->logger->warning('Discover library index failed', ['message' => $e->getMessage()]);

            return $items;
        }

        foreach ($items as $i => $item) {
            $info = $item['type'] === 'movie'
                ? ($library['movie'][$item['id']] ?? null)
                : ($library['tv']['tmdb_' . $item['id']] ?? null);

            if ($info !== null) {
                $items[$i]['in_library'] = true;
                $items[$i]['lib_status'] = $info['status'];
                $items[$i]['lib_id']     = $info['id'];
            }
        }

        return $items;
    }

    /** @return list<array{id:string, url:string, source:string, label:string, added_at:string}> */
    private function pinned(): array
    {
        $raw = $this->config->get(self::PINNED_KEY);
        if ($raw === null) {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }
}
