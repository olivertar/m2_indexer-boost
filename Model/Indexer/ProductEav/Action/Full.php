<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Indexer\ProductEav\Action;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Indexer\Product\Eav\Action\Full as CoreFull;
use Magento\Catalog\Model\ResourceModel\Indexer\ActiveTableSwitcher;
use Magento\Catalog\Model\ResourceModel\Product\Indexer\Eav\BatchSizeCalculator;
use Magento\Catalog\Model\ResourceModel\Product\Indexer\Eav\DecimalFactory;
use Magento\Catalog\Model\ResourceModel\Product\Indexer\Eav\SourceFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DB\Query\BatchIteratorInterface;
use Magento\Framework\DB\Query\Generator as QueryGenerator;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Indexer\BatchProviderInterface;
use Magento\Store\Model\ScopeInterface;
use Orangecat\IndexerBoost\Model\Indexer\TemporaryTable;

/**
 * Full catalog_product_attribute reindex of the core, with batches by key and the work table emptied by TRUNCATE
 *
 * Same steps as the core execute(), whose collaborators are private. Two changes, both in how the batches run:
 * - batches of product IDs by key (entity_id > last, LIMIT n) instead of LIMIT n OFFSET m, which reads all the
 *   previous IDs again in every batch; the core iterator stays when entity_id is not unique (staging, row_id);
 * - the TEMPORARY work table of the indexer is truncated before every batch, so the DELETE of the core
 *   clearTemporaryIndexTable() finds it empty instead of full of delete-marked rows.
 */
class Full extends CoreFull
{
    /**
     * @param DecimalFactory $eavDecimalFactory
     * @param SourceFactory $eavSourceFactory
     * @param MetadataPool $metadataPool
     * @param BatchProviderInterface $batchProvider
     * @param BatchSizeCalculator $batchSizeCalculator
     * @param ActiveTableSwitcher $activeTableSwitcher
     * @param ScopeConfigInterface $scopeConfig
     * @param QueryGenerator $batchQueryGenerator
     * @param TemporaryTable $temporaryTable
     */
    public function __construct(
        DecimalFactory $eavDecimalFactory,
        SourceFactory $eavSourceFactory,
        private readonly MetadataPool $metadataPool,
        BatchProviderInterface $batchProvider,
        private readonly BatchSizeCalculator $batchSizeCalculator,
        private readonly ActiveTableSwitcher $activeTableSwitcher,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly QueryGenerator $batchQueryGenerator,
        private readonly TemporaryTable $temporaryTable
    ) {
        parent::__construct(
            $eavDecimalFactory,
            $eavSourceFactory,
            $metadataPool,
            $batchProvider,
            $batchSizeCalculator,
            $activeTableSwitcher,
            $scopeConfig,
            $batchQueryGenerator
        );
    }

    /**
     * Execute full reindex
     *
     * @param int[]|int|null $ids
     * @return void
     * @throws LocalizedException
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function execute($ids = null): void
    {
        if (!(bool)$this->scopeConfig->getValue(self::ENABLE_EAV_INDEXER, ScopeInterface::SCOPE_STORE)) {
            return;
        }
        try {
            $entityMetadata = $this->metadataPool->getMetadata(ProductInterface::class);
            foreach ($this->getIndexers() as $indexerName => $indexer) {
                $connection = $indexer->getConnection();
                if (!$connection) {
                    throw new LocalizedException(__('No database connection for the %1 EAV indexer', $indexerName));
                }
                $mainTable = $this->activeTableSwitcher->getAdditionalTableName($indexer->getMainTable());
                $connection->truncateTable($mainTable);

                $select = $connection->select();
                $select->distinct(true);
                $select->from(['e' => $entityMetadata->getEntityTable()], $entityMetadata->getIdentifierField());

                $batchQueries = $this->batchQueryGenerator->generate(
                    $entityMetadata->getIdentifierField(),
                    $select,
                    $this->batchSizeCalculator->estimateBatchSize($connection, $indexerName),
                    $this->batchStrategy($entityMetadata)
                );

                foreach ($batchQueries as $query) {
                    $entityIds = $connection->fetchCol($query);
                    if (!empty($entityIds)) {
                        $this->temporaryTable->truncate($connection, $indexer->getIdxTable());
                        $indexer->reindexEntities($this->processRelations($indexer, $entityIds, true));
                        $this->syncData($indexer, $mainTable);
                    }
                }
                $this->activeTableSwitcher->switchTable($connection, [$indexer->getMainTable()]);
            }
        } catch (\Exception $e) {
            throw new LocalizedException(__($e->getMessage()), $e);
        }
    }

    /**
     * Batch by key when the identifier is unique in the entity table, as in Magento Open Source
     *
     * @param EntityMetadataInterface $entityMetadata
     * @return string
     */
    private function batchStrategy(EntityMetadataInterface $entityMetadata): string
    {
        return $entityMetadata->getLinkField() === $entityMetadata->getIdentifierField()
            ? BatchIteratorInterface::UNIQUE_FIELD_ITERATOR
            : BatchIteratorInterface::NON_UNIQUE_FIELD_ITERATOR;
    }
}
