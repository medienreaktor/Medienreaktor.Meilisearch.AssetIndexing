<?php
declare(strict_types=1);

namespace Medienreaktor\Meilisearch\AssetIndexing\Indexer;

use Flowpack\EntityUsage\EntityUsageStorageInterface;
use Medienreaktor\Meilisearch\Domain\Service\DimensionsService;
use Medienreaktor\Meilisearch\Domain\Service\IndexInterface;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Neos\Service\LinkingService;
use Psr\Log\LoggerInterface;
use Medienreaktor\Meilisearch\AssetIndexing\Service\PdfPageExtractor;
use Medienreaktor\Meilisearch\AssetIndexing\Service\PdfChunkingService;

/**
 * Asset indexer using Flowpack.EntityUsage (serviceId = neos_cr).
 *
 * Creates Meilisearch documents for media assets in the same index as nodes and
 * models dimension variants based on usage metadata.
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
     * @var EntityUsageStorageInterface
     */
    protected $usageStorage;

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
     * @var DimensionsService
     */
    protected $dimensionsService;

    /**
     * @Flow\Inject
     * @var ContextFactoryInterface
     */
    protected $contextFactory;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject
     * @var LinkingService
     */
    protected $linkingService;

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
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * Whether adaptive PDF chunking is enabled (single full-text document otherwise).
     * @Flow\InjectConfiguration(package="Medienreaktor.Meilisearch.AssetIndexing", path="chunking.enabled")
     * @var bool
     */
    protected $chunkingEnabled = true;

    /**
     * Index all assets based on recorded usages. Optionally purge existing asset documents first.
     *
     * @param bool $purgeDocuments Default false: perform a purge of all existing asset documents before indexing
     */
    public function indexAll(bool $purgeDocuments = false): void
    {
        if ($purgeDocuments) {
            $this->purgeAllAssetDocuments();
        }
        $usages = $this->usageStorage->getUsagesForService('neos_cr');
        $grouped = [];
        foreach ($usages as $usage) {
            $grouped[$usage->getEntityId()][] = $usage;
        }
        foreach ($grouped as $assetId => $assetUsages) {
            $asset = $this->assetRepository->findByIdentifier($assetId);
            if ($asset instanceof AssetInterface) {
                $this->indexAsset($asset, $assetUsages);
            }
        }
    }

    /**
     * Purge all Asset-Dokumente via Index-Filter (nutzt deleteByFilter auf dem IndexClient).
     */
    public function purgeAllAssetDocuments(): void
    {
        $filter = ['__nodeTypeAndSupertypes = "Neos.Media:Asset"'];
        $this->indexClient->deleteByFilter($filter);
    }

    /**
     * Reindex a single asset (all dimension variants) by persistence identifier.
     * Existing documents are deleted first.
     * @param string $assetId
     * @return void
     */
    public function reindexAssetByIdentifier(string $assetId): void
    {
        $existing = (array)$this->indexClient->findAllIdentifiersByIdentifier($assetId);
        if ($existing) {
            $this->indexClient->deleteDocuments($existing);
        }
        $usages = $this->getUsagesForAsset($assetId);
        if ($usages === []) { return; }
        $asset = $this->assetRepository->findByIdentifier($assetId);
        if ($asset instanceof AssetInterface) {
            $this->indexAsset($asset, $usages);
        }
    }

    /**
     * Remove all dimension entries for a given asset object.
     * @param AssetInterface $asset
     * @return void
     */
    public function removeAsset(AssetInterface $asset): void
    {
        $this->removeAssetByIdentifier($this->getPersistenceIdentifier($asset));
    }

    /**
     * Remove all asset documents referencing given asset persistence identifier.
     * @param string $assetPersistenceId
     * @return void
     */
    public function removeAssetByIdentifier(string $assetPersistenceId): void
    {
        $ids = (array)$this->indexClient->findAllIdentifiersByIdentifier($assetPersistenceId);
        if ($ids) {
            $this->indexClient->deleteDocuments($ids);
        }
    }

    /**
     * Index a single asset for each distinct dimension hash derived from usage metadata.
     * @param AssetInterface $asset
     * @param array<int,mixed> $assetUsages Usage list for this asset
     * @return void
     */
    public function indexAsset(AssetInterface $asset, array $assetUsages): void
    {
        $resource  = $asset->getResource();
        $mediaType = $resource->getMediaType();
        if (!$this->isMediaTypeAllowed($mediaType)) { return; }

        $siteName   = $this->determineSiteNameFromUsages($assetUsages);
        $doneHashes = [];
        $documents  = [];

        foreach ($assetUsages as $usage) {
            $meta = $usage->getMetadata();
            if (empty($meta['dimensions'])) { continue; }
            $dimensions = json_decode($meta['dimensions'], true) ?: [];
            if ($dimensions === []) { continue; }
            $hash = $this->dimensionsService->hash($dimensions);
            if (isset($doneHashes[$hash])) { continue; }
            $doneHashes[$hash] = true;

            $built = $this->buildDocuments($asset, $dimensions, $hash, $siteName);
            if ($built) {
                if (isset($built['id'])) { $documents[] = $built; }
                elseif (is_array($built)) { $documents = array_merge($documents, $built); }
            }
        }

        if ($documents) { $this->indexClient->addDocuments($documents); }
    }

    /**
     * Return Flow persistence identifier of an asset.
     * @param AssetInterface $asset
     * @return string
     */
    public function getPersistenceIdentifier(AssetInterface $asset): string
    {
        return (string)$this->persistenceManager->getIdentifierByObject($asset);
    }

    /**
     * Collect all usages (materialize QueryResult to array) for an asset id.
     * @param string $assetId Asset persistence identifier
     * @return array<int,mixed>
     */
    public function getUsagesForAsset(string $assetId): array
    {
        $result = [];
        foreach ($this->usageStorage->getUsages($assetId) as $usage) {
            $result[] = $usage;
        }
        return $result;
    }

    /**
     * Build one (non-PDF) or many (PDF) documents for a dimension variant.
     * Applies adaptive chunking for PDF if enabled; otherwise single full-text doc.
     * @param AssetInterface $asset
     * @param array<string,mixed> $dimensions
     * @param string $hash Dimension hash
     * @param string $siteName Site name resolved from usages
     * @return array<int,array<string,mixed>>|array<string,mixed>|null
     */
    protected function buildDocuments(AssetInterface $asset, array $dimensions, string $hash, string $siteName): array|null
    {
        $this->logger->debug(sprintf('Building documents for asset %s with dimensions %s', $asset->getTitle(), json_encode($dimensions)));
        $resource       = $asset->getResource();
        $mediaType      = $resource->getMediaType();
        $assetId        = $this->getPersistenceIdentifier($asset);
        $pseudoNodeType = $this->mapMediaType($mediaType);
        $title          = $asset->getTitle();
        $path           = '/sites/' . $siteName . '/assets/' . $assetId;
        $parentPath     = ['/', '/sites', '/sites/' . $siteName, '/sites/' . $siteName . '/assets'];
        $tags           = $this->extractTagLabels($asset);

        if (str_starts_with(strtolower($mediaType), 'application/pdf') || str_contains(strtolower($mediaType), 'pdf')) {
            try { $pageTexts = $this->pdfPageExtractor->extractPages($resource); }
            catch (\Throwable $e) { $this->logger->debug(sprintf('PDF page extraction failed for %s: %s', $resource->getFilename(), $e->getMessage())); $pageTexts = []; }
            if ($pageTexts === []) { $pageTexts = [1 => ($this->pdfPageExtractor->extractWholeText($resource) ?: 'PDF (empty)')]; }

            $chunks = $this->pdfChunkingService->buildChunks($pageTexts);
            // If only one chunk and chunking disabled upstream semantics, we still treat uniformly
            $documents = [];
            foreach ($chunks as $chunk) {
                $chunkText = $chunk['text'];
                if ($tags) { $chunkText .= "\n\n### Tags\n\n" . implode(', ', $tags); }
                $firstPage = $chunk['page_start'];
                $uri       = 'asset://' . $assetId . '#page=' . $firstPage;
                $doc = [
                    'id'                        => $this->generateIdentifier($assetId, $hash, $chunk['chunkNumber'] ?: 0),
                    '__identifier'              => 'asset_'.$assetId,
                    '__dimensions'              => $dimensions,
                    '__dimensionsHash'          => $hash,
                    '__uri'                     => $uri,
                    '__filename'                => $resource->getFilename(),
                    '__mediaType'               => $mediaType,
                    '__nodeType'                => $pseudoNodeType,
                    '__nodeTypeAndSupertypes'   => [$pseudoNodeType, 'Neos.Media:Asset', 'Neos.Neos:Document'],
                    '__path'                    => $path,
                    '__parentPath'              => $parentPath,
                    '__markdown'                => $chunkText,
                    '__fulltext'                => ['text' => $chunkText],
                    '__isAsset'                 => true,
                    '__pageStart'               => $chunk['page_start'],
                    '__pageEnd'                 => $chunk['page_end'],
                    '__pages'                   => $chunk['pages'],
                    '__chunkNumber'             => $chunk['chunkNumber'],
                    'title'                     => $title,
                    'filesize'                  => $resource->getFileSize(),
                    'tags'                      => $tags,
                ];
                if (isset($chunk['sectionCount'])) { $doc['__sectionCount'] = $chunk['sectionCount']; }
                if (isset($chunk['adaptiveTarget'])) { $doc['__adaptiveTarget'] = $chunk['adaptiveTarget']; }
                $documents[] = $doc;
            }
            return $documents;
        }

        $baseText = 'Asset: ' . $title . "\n\nFilename: " . $resource->getFilename();
        if ($tags) { $baseText .= "\n\n### Tags\n\n" . implode(', ', $tags); }
        return [
            'id'                      => $this->generateIdentifier($assetId, $hash),
            '__identifier'            => $assetId,
            '__dimensions'            => $dimensions,
            '__dimensionsHash'        => $hash,
            '__uri'                   => 'asset://' . $assetId,
            '__filename'              => $resource->getFilename(),
            '__collection'            => null,
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
     * Generate base or chunk-specific identifier.
     * @param string $assetId
     * @param string $hash
     * @param int|null $chunkNumber
     * @return string
     */
    protected function generateIdentifier(string $assetId, string $hash, ?int $chunkNumber = null): string
    {
        return 'asset_' . $assetId . '_' . $hash . ($chunkNumber !== null ? '_c' . $chunkNumber : '');
    }

    /**
     * Map a media type prefix to a pseudo node type (configured mapping) or default.
     * @param string $mediaType
     * @return string
     */
    protected function mapMediaType(string $mediaType): string
    {
        foreach ($this->mediaTypeMapping as $prefix => $nodeType) {
            if (str_starts_with($mediaType, $prefix)) { return $nodeType; }
        }
        return 'Neos.Media:Asset';
    }

    /**
     * Derive a site name from any usage by inspecting node path of a referenced node.
     * @param array<int,mixed> $assetUsages
     * @return string
     */
    protected function determineSiteNameFromUsages(array $assetUsages): string
    {
        foreach ($assetUsages as $usage) {
            $meta = $usage->getMetadata();
            if (empty($meta['nodeIdentifier']) || empty($meta['dimensions'])) { continue; }
            $dimensions = json_decode($meta['dimensions'], true) ?: [];
            $workspace  = $meta['workspace'] ?? 'live';
            try {
                $context = $this->contextFactory->create([
                    'workspaceName'           => $workspace,
                    'dimensions'              => $dimensions,
                    'invisibleContentShown'   => true,
                    'removedContentShown'     => true,
                    'inaccessibleContentShown'=> true,
                ]);
                $node = $context->getNodeByIdentifier($meta['nodeIdentifier']);
                if ($node) {
                    $segments = explode('/', trim($node->getPath(), '/'));
                    if (($segments[0] ?? '') === 'sites' && isset($segments[1])) { return $segments[1]; }
                }
            } catch (\Throwable $e) { continue; }
        }
        return 'unknown';
    }

    /**
     * Extract sorted unique tag labels for an asset (best-effort; assumes getTags() support).
     * @param AssetInterface $asset
     * @return array<int,string>
     */
    private function extractTagLabels(AssetInterface $asset): array
    {
        $tags = $asset->getTags(); // assumption: implementation supports tags
        if ($tags === null) { return []; }
        $labels = [];
        foreach ($tags as $tag) {
            if (is_object($tag) && method_exists($tag, 'getLabel')) {
                $label = trim((string)$tag->getLabel());
                if ($label !== '') { $labels[] = $label; }
            }
        }
        $labels = array_values(array_unique($labels));
        sort($labels, SORT_NATURAL | SORT_FLAG_CASE);
        return $labels;
    }

    /**
     * Media type allow-list check (prefix based). Empty list => unrestricted.
     * @param string $mediaType
     * @return bool
     */
    protected function isMediaTypeAllowed(string $mediaType): bool
    {
        if ($this->allowedMediaTypePrefixes === []) { return true; }
        foreach ($this->allowedMediaTypePrefixes as $prefix) {
            if (str_starts_with($mediaType, $prefix)) { return true; }
        }
        return false;
    }
}
