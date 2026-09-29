<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\AssetIndexing\Search;

use Medienreaktor\Meilisearch\Domain\Service\IndexInterface;
use Meilisearch\Exceptions\ExceptionInterface as MeilisearchException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Log\ThrowableStorageInterface;
use Neos\Flow\Log\Utility\LogEnvironment;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Repository\AssetRepository;
use Psr\Log\LoggerInterface;

/**
 * Frontend search over the shared node index: pages and indexed PDFs in one
 * relevance-ranked list.
 *
 * A PDF is indexed as many chunk documents, so the main query collapses them to one
 * hit per document (`distinct: __identifier`, Meilisearch >= 1.9). For the PDFs on the
 * current page a second query fetches every matching chunk, which gives the pages the
 * term was found on.
 *
 * @Flow\Scope("singleton")
 */
class SearchService
{
    private const HIGHLIGHT_PRE = '<em>';
    private const HIGHLIGHT_POST = '</em>';

    /**
     * Upper bound for the chunks fetched per result page to list PDF occurrences.
     */
    private const MAX_OCCURRENCE_CHUNKS = 200;

    /**
     * Prefix the AssetIndexer puts in front of the asset id in `__identifier`.
     */
    private const ASSET_IDENTIFIER_PREFIX = 'asset_';

    /**
     * @Flow\Inject
     * @var IndexInterface
     */
    protected $indexClient;

    /**
     * @Flow\Inject
     * @var AssetRepository
     */
    protected $assetRepository;

    /**
     * @Flow\Inject
     * @var ResourceManager
     */
    protected $resourceManager;

    /**
     * @Flow\Inject
     * @var ThrowableStorageInterface
     */
    protected $throwableStorage;

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @param string $dimensionsHash scopes the search to one dimension space point
     * @param int $page 1-based
     */
    public function search(string $query, string $dimensionsHash, int $page, int $perPage): SearchResult|SearchUnavailable
    {
        $query = trim($query);
        if ($query === '') {
            return SearchResult::empty();
        }

        try {
            $result = $this->indexClient->search($query, [
                'filter' => '__dimensionsHash = "' . $dimensionsHash . '"',
                'distinct' => '__identifier',
                'attributesToHighlight' => ['__fulltext.text', '__fulltext.h1', '__fulltext.h2'],
                'attributesToCrop' => ['__fulltext.text'],
                'cropLength' => 45,
                'highlightPreTag' => self::HIGHLIGHT_PRE,
                'highlightPostTag' => self::HIGHLIGHT_POST,
                'page' => max(1, $page),
                'hitsPerPage' => max(1, $perPage),
            ]);
        } catch (MeilisearchException $e) {
            $this->logFailure('Search query failed', $e, $query, $dimensionsHash, 'error');
            return new SearchUnavailable();
        }

        $rawHits = $result->getHits();
        $occurrences = $this->findPdfOccurrences($query, $dimensionsHash, $this->pdfIdentifiers($rawHits));

        $hits = [];
        foreach ($rawHits as $rawHit) {
            $hits[] = empty($rawHit['__isAsset'])
                ? $this->nodeHit($rawHit)
                : $this->pdfHit($rawHit, $query, $occurrences[$rawHit['__identifier']] ?? []);
        }

        return new SearchResult($result->getTotalHits() ?? 0, $result->getTotalPages() ?? 0, $hits);
    }

    /**
     * @param array<int,array<string,mixed>> $rawHits
     * @return list<string>
     */
    private function pdfIdentifiers(array $rawHits): array
    {
        $identifiers = [];
        foreach ($rawHits as $rawHit) {
            if (!empty($rawHit['__isAsset']) && isset($rawHit['__identifier'])) {
                $identifiers[$rawHit['__identifier']] = true;
            }
        }
        return array_keys($identifiers);
    }

    /**
     * @param array<string,mixed> $rawHit
     */
    private function nodeHit(array $rawHit): NodeHit
    {
        return new NodeHit(
            (string)($rawHit['title'] ?? $rawHit['uriPathSegment'] ?? ''),
            (string)($rawHit['__uri'] ?? ''),
            $this->snippet($rawHit),
        );
    }

    /**
     * @param array<string,mixed> $rawHit the best-ranked chunk of the PDF
     * @param array<int,array{page:int,snippet:string}> $pages matching pages, ascending
     */
    private function pdfHit(array $rawHit, string $query, array $pages): PdfHit
    {
        $baseUri = $this->publicAssetUri($this->assetId((string)$rawHit['__identifier']));
        $bestPage = (int)($rawHit['__pageStart'] ?? 1);

        // The occurrence lookup failed or found nothing: the hit itself is still one.
        if ($pages === []) {
            $pages = [['page' => $bestPage, 'snippet' => $this->snippet($rawHit)]];
        }

        $occurrences = array_map(
            fn (array $page): PdfOccurrence => new PdfOccurrence($page['page'], $page['snippet'], $this->deepLink($baseUri, $page['page'], $query)),
            $pages
        );

        $title = (string)($rawHit['title'] ?? '');
        $filename = (string)($rawHit['__filename'] ?? '');

        return new PdfHit(
            $title !== '' ? $title : $filename,
            $filename,
            (int)($rawHit['filesize'] ?? 0),
            $this->deepLink($baseUri, $bestPage, $query),
            $this->snippet($rawHit),
            array_values($occurrences),
        );
    }

    /**
     * Fetch every matching chunk of the given PDFs and group them into pages.
     *
     * A failure here only costs the page list, so it degrades instead of failing the
     * search: each PDF hit then shows the page of its best-ranked chunk.
     *
     * @param list<string> $identifiers `__identifier` values of PDF hits
     * @return array<string,array<int,array{page:int,snippet:string}>> pages per identifier, ascending
     */
    private function findPdfOccurrences(string $query, string $dimensionsHash, array $identifiers): array
    {
        if ($identifiers === []) {
            return [];
        }

        $quoted = array_map(static fn (string $identifier): string => '"' . $identifier . '"', $identifiers);
        try {
            $result = $this->indexClient->search($query, [
                'filter' => '__dimensionsHash = "' . $dimensionsHash . '" AND __isAsset = true AND __identifier IN [' . implode(', ', $quoted) . ']',
                'attributesToHighlight' => ['__fulltext.text'],
                'attributesToCrop' => ['__fulltext.text'],
                'cropLength' => 30,
                'highlightPreTag' => self::HIGHLIGHT_PRE,
                'highlightPostTag' => self::HIGHLIGHT_POST,
                'page' => 1,
                'hitsPerPage' => self::MAX_OCCURRENCE_CHUNKS,
            ]);
        } catch (MeilisearchException $e) {
            $this->logFailure('PDF occurrence lookup failed, showing one page per PDF', $e, $query, $dimensionsHash, 'warning');
            return [];
        }

        $pagesByIdentifier = [];
        foreach ($result->getHits() as $rawHit) {
            $identifier = $rawHit['__identifier'] ?? null;
            if ($identifier === null) {
                continue;
            }
            $page = (int)($rawHit['__pageStart'] ?? 1);
            // Hits arrive best-ranked first, so the first snippet per page is the best one.
            $pagesByIdentifier[$identifier][$page] ??= ['page' => $page, 'snippet' => $this->snippet($rawHit)];
        }

        foreach ($pagesByIdentifier as $identifier => $pages) {
            ksort($pages);
            $pagesByIdentifier[$identifier] = array_values($pages);
        }
        return $pagesByIdentifier;
    }

    /**
     * @param array<string,mixed> $rawHit
     */
    private function snippet(array $rawHit): string
    {
        $formatted = $rawHit['_formatted']['__fulltext'] ?? [];
        foreach (['text', 'h1', 'h2'] as $key) {
            if (!empty($formatted[$key])) {
                return (string)$formatted[$key];
            }
        }
        return '';
    }

    /**
     * Link that opens the PDF on the given page with the term marked, using the PDF
     * open parameters browsers' viewers understand. Empty if the asset has no URI.
     */
    private function deepLink(?string $baseUri, int $page, string $query): string
    {
        if ($baseUri === null) {
            return '';
        }
        return $baseUri . '#page=' . max(1, $page) . '&search=' . rawurlencode($query);
    }

    private function assetId(string $identifier): string
    {
        return str_starts_with($identifier, self::ASSET_IDENTIFIER_PREFIX)
            ? substr($identifier, strlen(self::ASSET_IDENTIFIER_PREFIX))
            : $identifier;
    }

    /**
     * Null if the asset or its resource no longer exists — the index can lag behind
     * a deletion until the next asset reindex.
     */
    private function publicAssetUri(string $assetId): ?string
    {
        $asset = $this->assetRepository->findByIdentifier($assetId);
        if (!$asset instanceof AssetInterface) {
            return null;
        }
        $uri = $this->resourceManager->getPublicPersistentResourceUri($asset->getResource());
        return is_string($uri) && $uri !== '' ? $uri : null;
    }

    private function logFailure(string $message, MeilisearchException $exception, string $query, string $dimensionsHash, string $level): void
    {
        $context = ['query' => $query, 'dimensionsHash' => $dimensionsHash];
        $reference = $this->throwableStorage->logThrowable($exception, $context);
        $this->logger->log($level, $message . ' — ' . $reference, array_merge($context, LogEnvironment::fromMethodName(__METHOD__)));
    }
}
