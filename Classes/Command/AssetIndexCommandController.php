<?php
declare(strict_types=1);

namespace Medienreaktor\Meilisearch\AssetIndexing\Command;

use Medienreaktor\Meilisearch\AssetIndexing\Indexer\AssetIndexer;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Media\Domain\Model\AssetInterface;

/**
 * CLI Commands für Asset Indexierung
 */
class AssetIndexCommandController extends CommandController
{
    /** @Flow\Inject */
    protected AssetIndexer $assetIndexer;

    /** @Flow\Inject */
    protected AssetRepository $assetRepository;

    /**
     * Perform (re)indexing of all referenced assets. Optional global purge can be disabled (--purgeDocuments false)
     * @param bool $purgeDocuments Default false: purge all existing asset documents before indexing
     */
    protected function indexAll(bool $purgeDocuments = false): void
    {
        $this->outputLine('Index assets (purgeDocuments=%s) ...', [$purgeDocuments ? 'true' : 'false']);
        $this->assetIndexer->indexAll($purgeDocuments);
        $this->outputLine('Done');
    }

    public function purgeIndexedDocumentsCommand(): void
    {
        $this->outputLine('Purge all asset documents ...');
        $this->assetIndexer->purgeAllAssetDocuments();
        $this->outputLine('Done');
    }

    /**
     * Einzelnes Asset (Persistence Identifier aus Medienverwaltung) reindexen.
     * Beispiel: ./flow assetindex:reindexasset --assetIdentifier bb8d26dc-9033-41eb-85b3-5e35dc7a6226
     *
     * @param string $assetIdentifier
     */
    public function reindexAssetCommand(string $assetIdentifier): void
    {
        $asset = $this->assetRepository->findByIdentifier($assetIdentifier);
        if (!$asset instanceof AssetInterface) {
            $this->outputLine('Asset nicht gefunden: %s', [$assetIdentifier]);
            return;
        }
        $this->outputLine('Reindex Asset %s ...', [$assetIdentifier]);
        $this->assetIndexer->reindexAssetByIdentifier($assetIdentifier);
        $this->outputLine('Fertig');
    }

    /**
     * Einzelnes Asset aus dem Index entfernen (alle Dimensions-Varianten).
     * Beispiel: ./flow assetindex:removeasset --assetIdentifier bb8d26dc-9033-41eb-85b3-5e35dc7a6226
     *
     * @param string $assetIdentifier
     */
    public function removeAssetCommand(string $assetIdentifier): void
    {
        $asset = $this->assetRepository->findByIdentifier($assetIdentifier);
        if (!$asset instanceof AssetInterface) {
            $this->outputLine('Asset nicht gefunden: %s', [$assetIdentifier]);
            return;
        }
        $this->outputLine('Entferne Asset %s aus Index ...', [$assetIdentifier]);
        $this->assetIndexer->removeAssetByIdentifier($assetIdentifier);
        $this->outputLine('Fertig');
    }
}
