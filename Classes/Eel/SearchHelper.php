<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\AssetIndexing\Eel;

use Medienreaktor\Meilisearch\AssetIndexing\Search\SearchResult;
use Medienreaktor\Meilisearch\AssetIndexing\Search\SearchService;
use Medienreaktor\Meilisearch\AssetIndexing\Search\SearchUnavailable;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\Eel\ProtectedContextAwareInterface;
use Neos\Flow\Annotations as Flow;

/**
 * Fusion access to the mixed page and PDF search, as `AssetIndexing.Search`:
 *
 *   ${AssetIndexing.Search.results(documentNode, query, page, perPage)}
 *
 * The result's `type` is `result` or `unavailable`; each hit's `type` is `node` or `pdf`.
 */
class SearchHelper implements ProtectedContextAwareInterface
{
    #[Flow\Inject]
    protected SearchService $searchService;

    /**
     * Search in the dimensions of the given node.
     */
    public function results(Node $contextNode, string $query, int $page = 1, int $perPage = 10): SearchResult|SearchUnavailable
    {
        return $this->searchService->search($query, $contextNode->dimensionSpacePoint->hash, $page, $perPage);
    }

    public function allowsCallOfMethod($methodName): bool
    {
        return $methodName === 'results';
    }
}
