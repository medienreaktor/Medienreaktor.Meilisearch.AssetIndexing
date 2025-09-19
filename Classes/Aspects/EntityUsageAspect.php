<?php
declare(strict_types=1);

namespace Medienreaktor\Meilisearch\AssetIndexing\Aspects;

use Flowpack\EntityUsage\Service\EntityUsageService;
use Medienreaktor\Meilisearch\AssetIndexing\Indexer\AssetIndexer;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Aop\JoinPointInterface;

/**
 * Reagiert direkt auf registerUsage/unregisterUsage im EntityUsageService
 * und aktualisiert betroffene Asset-Dokumente im Index.
 *
 * @Flow\Aspect
 * @Flow\Scope("singleton")
 */
class EntityUsageAspect
{
    /** @Flow\Inject */
    protected AssetIndexer $assetIndexer;

    /**
     * Nach Registrierung -> Asset reindexen
     * @Flow\AfterReturning("method(Flowpack\\EntityUsage\\Service\\EntityUsageService->registerUsage())")
     */
    public function afterRegister(JoinPointInterface $joinPoint): void
    {
        $arguments = $joinPoint->getMethodArguments();
        // registerUsage($usageId, $entityId, $metadata)
        $entityId = $arguments['entityId'] ?? null;
        if (is_string($entityId)) {
            $this->assetIndexer->reindexAssetByIdentifier($entityId);
        }
    }

    /**
     * Nach Deregistrierung -> Asset ggf. neu indexieren oder entfernen falls keine Usage mehr.
     * @Flow\AfterReturning("method(Flowpack\\EntityUsage\\Service\\EntityUsageService->unregisterUsage())")
     */
    public function afterUnregister(JoinPointInterface $joinPoint): void
    {
        $arguments = $joinPoint->getMethodArguments();
        $entityId = $arguments['entityId'] ?? null;
        if (!is_string($entityId)) { return; }
        $usages = $this->assetIndexer->getUsagesForAsset($entityId);
        if ($usages === []) {
            $this->assetIndexer->removeAssetByIdentifier($entityId);
            return;
        }
        $this->assetIndexer->reindexAssetByIdentifier($entityId);
    }
}
