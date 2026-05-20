<?php
declare(strict_types=1);

namespace Medienreaktor\Meilisearch\AssetIndexing\Indexer;

use Medienreaktor\Meilisearch\AssetIndexing\Service\PdfChunkingService;
use Medienreaktor\Meilisearch\AssetIndexing\Service\PdfPageExtractor;
use Medienreaktor\Meilisearch\Domain\Service\IndexInterface;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindClosestNodeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Neos\AssetUsage\AssetUsageService;
use Neos\Neos\AssetUsage\Domain\AssetUsage;
use Neos\Neos\AssetUsage\Dto\AssetUsageFilter;
use Psr\Log\LoggerInterface;

/**
 * Asset indexer for Neos 9 using the native Neos\Neos\AssetUsage\AssetUsageService.
 *
 * Creates Meilisearch documents for media assets in the same index as nodes and
 * models dimension variants based on the asset usage records.
 *
 * Identifier scheme (base): asset_<assetPersistenceId>_<dimensionsHash>[_c<chunkNumber>]
 * PDF assets may produce multiple chunk documents.
 *
 * @Flow\Scope("singleton")
 */
class AssetIndexer
{
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
     * @var IndexInterface
     */
    protected $indexClient;

    /**
     * @Flow\Inject
     * @var AssetUsageService
     */
    protected $assetUsageService;

    /**
     * @Flow\Inject
     * @var ContentRepositoryRegistry
     */
    protected $contentRepositoryRegistry;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject
     * @var PdfPageExtractor
     */
    protected $pdfPageExtractor;

    /**
     * @Flow\Inject
     * @var PdfChunkingService
     */
    protected $pdfChunkingService;

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @Flow\InjectConfiguration(package="Medienreaktor.Meilisearch.AssetIndexing", path="mediaTypeMapping")
     * @var array
     */
    protected $mediaTypeMapping = [];

    /**
     * @Flow\InjectConfiguration(package="Medienreaktor.Meilisearch.AssetIndexing", path="allowedMediaTypePrefixes")
     * @var array
     */
    protected $allowedMediaTypePrefixes = [];

    /**
     * @Flow\InjectConfiguration(package="Medienreaktor.Meilisearch.AssetIndexing", path="contentRepositoryId")
     * @var string
     */
    protected $contentRepositoryId = 'default';

    /**
     * Index all assets that are referenced anywhere in the live workspace.
     *
     * @param bool $purgeDocuments If true, all existing asset documents are removed before reindexing.
     */
    public function indexAll(bool $purgeDocuments = false): void
    {
        if ($purgeDocuments) {
            $this->purgeAllAssetDocuments();
        }

        $usagesByAsset = $this->collectUsagesByAsset();

        foreach ($usagesByAsset as $assetId => $usages) {
            $asset = $this->assetRepository->findByIdentifier($assetId);
            if ($asset instanceof AssetInterface) {
                $this->indexAsset($asset, $usages);
            }
        }
    }

    /**
     * Delete all asset documents in the index via filter.
     */
    public function purgeAllAssetDocuments(): void
    {
        $this->indexClient->deleteByFilter(['__isAsset = true']);
    }

    /**
     * Reindex a single asset (all dimension variants) by its persistence identifier.
     * Existing documents are deleted first.
     */
    public function reindexAssetByIdentifier(string $assetId): void
    {
        $existing = (array)$this->indexClient->findAllIdentifiersByIdentifier('asset_' . $assetId);
        if ($existing) {
            $this->indexClient->deleteDocuments($existing);
        }
        $usages = $this->getUsagesForAsset($assetId);
        if ($usages === []) {
            return;
        }
        $asset = $this->assetRepository->findByIdentifier($assetId);
        if ($asset instanceof AssetInterface) {
            $this->indexAsset($asset, $usages);
        }
    }

    /**
     * Remove all dimension entries for the given asset object.
     */
    public function removeAsset(AssetInterface $asset): void
    {
        $this->removeAssetByIdentifier($this->getPersistenceIdentifier($asset));
    }

    /**
     * Remove all asset documents referencing the given persistence identifier.
     */
    public function removeAssetByIdentifier(string $assetPersistenceId): void
    {
        $ids = (array)$this->indexClient->findAllIdentifiersByIdentifier('asset_' . $assetPersistenceId);
        if ($ids) {
            $this->indexClient->deleteDocuments($ids);
        }
    }

    /**
     * Build and submit Meilisearch documents for the asset, one (or more, for PDFs) per
     * distinct origin dimension space point in the given usages.
     *
     * @param array<int,AssetUsage> $assetUsages
     */
    public function indexAsset(AssetInterface $asset, array $assetUsages): void
    {
        $resource = $asset->getResource();
        $mediaType = $resource->getMediaType();
        if (!$this->isMediaTypeAllowed($mediaType)) {
            return;
        }

        $documents = [];
        $doneHashes = [];

        foreach ($assetUsages as $usage) {
            $origin = $usage->originDimensionSpacePoint;
            $hash = $origin->hash;
            if (isset($doneHashes[$hash])) {
                continue;
            }
            $doneHashes[$hash] = true;

            $siteName = $this->resolveSiteName($usage) ?? 'unknown';
            $built = $this->buildDocuments($asset, $origin, $siteName);
            if ($built === null) {
                continue;
            }
            if (isset($built['id'])) {
                $documents[] = $built;
            } else {
                $documents = array_merge($documents, $built);
            }
        }

        if ($documents) {
            $this->indexClient->addDocuments($documents);
        }
    }

    /**
     * Persistence identifier of an asset.
     */
    public function getPersistenceIdentifier(AssetInterface $asset): string
    {
        return (string)$this->persistenceManager->getIdentifierByObject($asset);
    }

    /**
     * Collect all usages of a single asset across all dimensions in the live workspace.
     *
     * @return array<int,AssetUsage>
     */
    public function getUsagesForAsset(string $assetId): array
    {
        $filter = AssetUsageFilter::create()
            ->withAsset($assetId)
            ->withWorkspaceName(WorkspaceName::forLive());

        $usages = $this->assetUsageService->findByFilter(
            ContentRepositoryId::fromString($this->contentRepositoryId),
            $filter
        );

        $result = [];
        foreach ($usages as $usage) {
            $result[] = $usage;
        }
        return $result;
    }

    /**
     * Iterate all asset usages in the live workspace once and group them by asset id.
     *
     * @return array<string,array<int,AssetUsage>>
     */
    protected function collectUsagesByAsset(): array
    {
        $filter = AssetUsageFilter::create()
            ->withWorkspaceName(WorkspaceName::forLive());

        $usages = $this->assetUsageService->findByFilter(
            ContentRepositoryId::fromString($this->contentRepositoryId),
            $filter
        );

        $grouped = [];
        foreach ($usages as $usage) {
            $grouped[$usage->assetId][] = $usage;
        }
        return $grouped;
    }

    /**
     * Build one (non-PDF) or many (PDF chunks) documents for a dimension variant.
     *
     * @return array<int,array<string,mixed>>|array<string,mixed>|null
     */
    protected function buildDocuments(AssetInterface $asset, OriginDimensionSpacePoint $origin, string $siteName): array|null
    {
        $resource = $asset->getResource();
        $mediaType = $resource->getMediaType();
        $assetId = $this->getPersistenceIdentifier($asset);
        $pseudoNodeType = $this->mapMediaType($mediaType);
        $title = $asset->getTitle();
        $hash = $origin->hash;
        $path = '/sites/' . $siteName . '/assets/' . $assetId;
        $parentPath = ['/', '/sites', '/sites/' . $siteName, '/sites/' . $siteName . '/assets'];
        $tags = $this->extractTagLabels($asset);

        $this->logger->debug(sprintf(
            'Building documents for asset %s [%s] dimensions=%s',
            $title,
            $assetId,
            $origin->toJson()
        ));

        if (str_contains(strtolower($mediaType), 'pdf')) {
            try {
                $pageTexts = $this->pdfPageExtractor->extractPages($resource);
            } catch (\Throwable $e) {
                $this->logger->debug(sprintf('PDF page extraction failed for %s: %s', $resource->getFilename(), $e->getMessage()));
                $pageTexts = [];
            }
            $hasContent = false;
            foreach ($pageTexts as $pageText) {
                if (trim((string)$pageText) !== '') {
                    $hasContent = true;
                    break;
                }
            }
            if (!$hasContent) {
                $whole = $this->pdfPageExtractor->extractWholeText($resource);
                if (trim($whole) !== '') {
                    $pageTexts = [1 => $whole];
                    $hasContent = true;
                }
            }
            if (!$hasContent) {
                $this->logger->warning(sprintf(
                    'PDF text extraction yielded no content for asset %s (%s). Check that "pdftotext" (poppler-utils) is installed in the runtime container.',
                    $assetId,
                    $resource->getFilename()
                ));
                $pageTexts = [1 => 'PDF (empty)'];
            }

            $chunks = $this->pdfChunkingService->buildChunks($pageTexts);
            $documents = [];
            foreach ($chunks as $chunk) {
                $chunkText = $chunk['text'];
                if ($tags) {
                    $chunkText .= "\n\n### Tags\n\n" . implode(', ', $tags);
                }
                $firstPage = $chunk['page_start'];
                $uri = 'asset://' . $assetId . '#page=' . $firstPage;
                $doc = [
                    'id'                      => $this->generateIdentifier($assetId, $hash, $chunk['chunkNumber'] ?? 0),
                    '__identifier'            => 'asset_' . $assetId,
                    '__dimensions'            => $origin->coordinates,
                    '__dimensionsHash'        => $hash,
                    '__uri'                   => $uri,
                    '__filename'              => $resource->getFilename(),
                    '__mediaType'             => $mediaType,
                    '__nodeType'              => $pseudoNodeType,
                    '__nodeTypeAndSupertypes' => [$pseudoNodeType, 'Neos.Media:Asset', 'Neos.Neos:Document'],
                    '__path'                  => $path,
                    '__parentPath'            => $parentPath,
                    '__markdown'              => $chunkText,
                    '__fulltext'              => ['text' => $chunkText],
                    '__isAsset'               => true,
                    '__pageStart'             => $chunk['page_start'],
                    '__pageEnd'               => $chunk['page_end'],
                    '__pages'                 => $chunk['pages'],
                    '__chunkNumber'           => $chunk['chunkNumber'] ?? 0,
                    'title'                   => $title,
                    'filesize'                => $resource->getFileSize(),
                    'tags'                    => $tags,
                ];
                if (isset($chunk['sectionCount'])) {
                    $doc['__sectionCount'] = $chunk['sectionCount'];
                }
                if (isset($chunk['adaptiveTarget'])) {
                    $doc['__adaptiveTarget'] = $chunk['adaptiveTarget'];
                }
                $documents[] = $doc;
            }
            return $documents;
        }

        $baseText = 'Asset: ' . $title . "\n\nFilename: " . $resource->getFilename();
        if ($tags) {
            $baseText .= "\n\n### Tags\n\n" . implode(', ', $tags);
        }
        return [
            'id'                      => $this->generateIdentifier($assetId, $hash),
            '__identifier'            => 'asset_' . $assetId,
            '__dimensions'            => $origin->coordinates,
            '__dimensionsHash'        => $hash,
            '__uri'                   => 'asset://' . $assetId,
            '__filename'              => $resource->getFilename(),
            '__mediaType'             => $mediaType,
            '__nodeType'              => $pseudoNodeType,
            '__nodeTypeAndSupertypes' => [$pseudoNodeType, 'Neos.Media:Asset', 'Neos.Neos:Document'],
            '__path'                  => $path,
            '__parentPath'            => $parentPath,
            '__markdown'              => $baseText,
            '__fulltext'              => ['text' => $baseText],
            '__isAsset'               => true,
            'title'                   => $title,
            'filesize'                => $resource->getFileSize(),
            'tags'                    => $tags,
        ];
    }

    /**
     * Build the document identifier (with optional chunk suffix for PDFs).
     */
    protected function generateIdentifier(string $assetId, string $hash, ?int $chunkNumber = null): string
    {
        return 'asset_' . $assetId . '_' . $hash . ($chunkNumber !== null ? '_c' . $chunkNumber : '');
    }

    /**
     * Map a media type prefix to a pseudo node type via configuration, or default to Neos.Media:Asset.
     */
    protected function mapMediaType(string $mediaType): string
    {
        foreach ($this->mediaTypeMapping as $prefix => $nodeType) {
            if (str_starts_with($mediaType, $prefix)) {
                return $nodeType;
            }
        }
        return 'Neos.Media:Asset';
    }

    /**
     * Find the Neos.Neos:Site ancestor of the usage's node and return its node name as site identifier.
     */
    protected function resolveSiteName(AssetUsage $usage): ?string
    {
        try {
            $contentRepository = $this->contentRepositoryRegistry->get($usage->contentRepositoryId);
            $contentGraph = $contentRepository->getContentGraph($usage->workspaceName);
            $subgraph = $contentGraph->getSubgraph(
                $usage->originDimensionSpacePoint->toDimensionSpacePoint(),
                VisibilityConstraints::withoutRestrictions()
            );
            $siteNode = $subgraph->findClosestNode(
                $usage->nodeAggregateId,
                FindClosestNodeFilter::create(nodeTypes: 'Neos.Neos:Site')
            );
            return $siteNode?->name?->value;
        } catch (\Throwable $e) {
            $this->logger->debug(sprintf('Site name resolution failed for usage of asset %s: %s', $usage->assetId, $e->getMessage()));
            return null;
        }
    }

    /**
     * Extract sorted unique tag labels for an asset (best-effort).
     *
     * @return array<int,string>
     */
    private function extractTagLabels(AssetInterface $asset): array
    {
        if (!method_exists($asset, 'getTags')) {
            return [];
        }
        $tags = $asset->getTags();
        if ($tags === null) {
            return [];
        }
        $labels = [];
        foreach ($tags as $tag) {
            if (is_object($tag) && method_exists($tag, 'getLabel')) {
                $label = trim((string)$tag->getLabel());
                if ($label !== '') {
                    $labels[] = $label;
                }
            }
        }
        $labels = array_values(array_unique($labels));
        sort($labels, SORT_NATURAL | SORT_FLAG_CASE);
        return $labels;
    }

    /**
     * Media type allow-list check. Empty list means unrestricted.
     */
    protected function isMediaTypeAllowed(string $mediaType): bool
    {
        if ($this->allowedMediaTypePrefixes === []) {
            return true;
        }
        foreach ($this->allowedMediaTypePrefixes as $prefix) {
            if (str_starts_with($mediaType, $prefix)) {
                return true;
            }
        }
        return false;
    }
}
