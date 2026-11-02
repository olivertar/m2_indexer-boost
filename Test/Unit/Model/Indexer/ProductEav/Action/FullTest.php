<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Indexer\ProductEav\Action;

use Magento\Catalog\Model\ResourceModel\Indexer\ActiveTableSwitcher;
use Magento\Catalog\Model\ResourceModel\Product\Indexer\Eav\BatchSizeCalculator;
use Magento\Catalog\Model\ResourceModel\Product\Indexer\Eav\Decimal;
use Magento\Catalog\Model\ResourceModel\Product\Indexer\Eav\DecimalFactory;
use Magento\Catalog\Model\ResourceModel\Product\Indexer\Eav\Source;
use Magento\Catalog\Model\ResourceModel\Product\Indexer\Eav\SourceFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Query\BatchIteratorInterface;
use Magento\Framework\DB\Query\Generator as QueryGenerator;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Indexer\BatchProviderInterface;
use Orangecat\IndexerBoost\Model\Indexer\ProductEav\Action\Full;
use Orangecat\IndexerBoost\Model\Indexer\TemporaryTable;
use PHPUnit\Framework\TestCase;

class FullTest extends TestCase
{
    /**
     * @var string[]
     */
    private array $log = [];

    public function testBatchesByKeyAndTruncatesTheTemporaryTableBeforeEveryBatch(): void
    {
        $generator = $this->createMock(QueryGenerator::class);
        $generator->expects($this->exactly(2))->method('generate')
            ->with('entity_id', $this->anything(), 1000, BatchIteratorInterface::UNIQUE_FIELD_ITERATOR)
            ->willReturnCallback(fn (): array => [$this->createStub(Select::class), $this->createStub(Select::class)]);

        $this->full($generator, 'entity_id')->execute();

        $this->assertSame([
            'truncate catalog_product_index_eav_replica',
            'truncate temporary catalog_product_index_eav_temp',
            'source reindexEntities 7,8',
            'sync catalog_product_index_eav_replica',
            'truncate temporary catalog_product_index_eav_temp',
            'source reindexEntities 7,8',
            'sync catalog_product_index_eav_replica',
            'switch catalog_product_index_eav',
            'truncate catalog_product_index_eav_decimal_replica',
            'switch catalog_product_index_eav_decimal',
        ], $this->log);
    }

    public function testStagingKeepsTheCoreIteratorBecauseEntityIdRepeats(): void
    {
        $generator = $this->createMock(QueryGenerator::class);
        $generator->expects($this->exactly(2))->method('generate')
            ->with('entity_id', $this->anything(), 1000, BatchIteratorInterface::NON_UNIQUE_FIELD_ITERATOR)
            ->willReturn([]);

        $this->full($generator, 'row_id')->execute();
    }

    /**
     * Full action over logging fakes: the source indexer gets IDs 7 and 8 per batch, the decimal one none
     *
     * @param QueryGenerator $generator
     * @param string $linkField
     * @return Full
     */
    private function full(QueryGenerator $generator, string $linkField): Full
    {
        $source = $this->indexer(Source::class, 'source', 'catalog_product_index_eav', [7, 8]);
        $decimal = $this->indexer(Decimal::class, 'decimal', 'catalog_product_index_eav_decimal', []);
        $sourceFactory = $this->createStub(SourceFactory::class);
        $sourceFactory->method('create')->willReturn($source);
        $decimalFactory = $this->createStub(DecimalFactory::class);
        $decimalFactory->method('create')->willReturn($decimal);

        $metadata = $this->createStub(EntityMetadataInterface::class);
        $metadata->method('getEntityTable')->willReturn('catalog_product_entity');
        $metadata->method('getIdentifierField')->willReturn('entity_id');
        $metadata->method('getLinkField')->willReturn($linkField);
        $metadataPool = $this->createStub(MetadataPool::class);
        $metadataPool->method('getMetadata')->willReturn($metadata);

        $batchSize = $this->createStub(BatchSizeCalculator::class);
        $batchSize->method('estimateBatchSize')->willReturn(1000);
        $switcher = $this->createStub(ActiveTableSwitcher::class);
        $switcher->method('getAdditionalTableName')
            ->willReturnCallback(fn (string $table): string => $table . '_replica');
        $switcher->method('switchTable')->willReturnCallback(function ($connection, array $tables): void {
            $this->log[] = 'switch ' . implode(',', $tables);
        });
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn('1');
        $temporaryTable = $this->createStub(TemporaryTable::class);
        $temporaryTable->method('truncate')->willReturnCallback(function ($connection, string $table): void {
            $this->log[] = 'truncate temporary ' . $table;
        });

        return new Full(
            $decimalFactory,
            $sourceFactory,
            $metadataPool,
            $this->createStub(BatchProviderInterface::class),
            $batchSize,
            $switcher,
            $scopeConfig,
            $generator,
            $temporaryTable
        );
    }

    /**
     * EAV indexer fake whose connection returns the given IDs for every batch
     *
     * @param class-string<Source|Decimal> $class
     * @param string $name
     * @param string $mainTable
     * @param int[] $batchIds
     * @return Source|Decimal
     */
    private function indexer(string $class, string $name, string $mainTable, array $batchIds): Source|Decimal
    {
        $connection = $this->createStub(AdapterInterface::class);
        $select = $this->createStub(Select::class);
        foreach (['distinct', 'from'] as $method) {
            $select->method($method)->willReturnSelf();
        }
        $connection->method('select')->willReturn($select);
        $connection->method('fetchCol')->willReturn($batchIds);
        $connection->method('describeTable')->willReturn(['entity_id' => []]);
        $connection->method('truncateTable')->willReturnCallback(function (string $table) use ($connection) {
            $this->log[] = 'truncate ' . $table;
            return $connection;
        });
        $connection->method('insertFromSelect')->willReturnCallback(
            function ($select, string $table): string {
                $this->log[] = 'sync ' . $table;
                return 'INSERT';
            }
        );

        $indexer = $this->createStub($class);
        $indexer->method('getConnection')->willReturn($connection);
        $indexer->method('getMainTable')->willReturn($mainTable);
        $indexer->method('getIdxTable')->willReturn($mainTable . '_temp');
        $indexer->method('getRelationsByChild')->willReturn([]);
        $indexer->method('reindexEntities')->willReturnCallback(function (array $ids) use ($name, $indexer) {
            $this->log[] = $name . ' reindexEntities ' . implode(',', $ids);
            return $indexer;
        });
        return $indexer;
    }
}
