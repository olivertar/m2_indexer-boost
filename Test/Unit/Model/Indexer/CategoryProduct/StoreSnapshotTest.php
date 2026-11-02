<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Indexer\CategoryProduct;

use Magento\Catalog\Model\Config;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Store\Model\Store;
use Orangecat\IndexerBoost\Model\Indexer\CategoryProduct\StoreSnapshot;
use Orangecat\IndexerBoost\Model\Indexer\PreparationException;
use PHPUnit\Framework\TestCase;

class StoreSnapshotTest extends TestCase
{
    public function testProductSnapshotIsRebuiltAsTemporaryTableOfTheStore(): void
    {
        $log = [];
        $connection = $this->connection($log);
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(3);
        $store->method('getWebsiteId')->willReturn(2);

        $table = $this->snapshot()->products($connection, $store, [2, 4]);

        $this->assertSame('orangecat_ib_cp_product_3', $table);
        $this->assertSame([
            'drop temporary orangecat_ib_cp_product_3',
            'create temporary orangecat_ib_cp_product_3 (entity_id,visibility,children_enabled)',
            'insert into orangecat_ib_cp_product_3 (entity_id,visibility,children_enabled)',
            'run INSERT',
        ], $log);
    }

    public function testProductSnapshotStartsFromTheWebsiteProducts(): void
    {
        $log = [];
        $outer = $this->createMock(Select::class);
        $outer->expects($this->once())->method('from')
            ->with(['cpw' => 'catalog_product_website'], [])
            ->willReturnSelf();
        foreach (['joinInner', 'joinLeft', 'where', 'columns'] as $method) {
            $outer->method($method)->willReturnSelf();
        }
        $subquery = $this->createStub(Select::class);
        foreach (['from', 'joinInner', 'joinLeft', 'where'] as $method) {
            $subquery->method($method)->willReturnSelf();
        }
        // Two EXISTS subqueries first, then the outer select
        $connection = $this->connection($log, [$subquery, $subquery, $outer]);
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(3);

        // MariaDB otherwise joins the website table last, by a range on website_id, through a block nested loop
        $this->snapshot()->products($connection, $store, [2, 4]);
    }

    public function testCategorySnapshotIsRebuiltAsTemporaryTableOfTheStore(): void
    {
        $log = [];
        $connection = $this->connection($log);
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(3);

        $table = $this->snapshot()->categories($connection, $store);

        $this->assertSame('orangecat_ib_cp_category_3', $table);
        $this->assertSame([
            'drop temporary orangecat_ib_cp_category_3',
            'create temporary orangecat_ib_cp_category_3 (entity_id,is_active,is_anchor)',
            'insert into orangecat_ib_cp_category_3 (entity_id,is_active,is_anchor)',
            'run INSERT',
        ], $log);
    }

    public function testADatabaseErrorWhileBuildingASnapshotIsAPreparationFailure(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('getTableName')->willReturnArgument(0);
        $table = $this->createStub(Table::class);
        $table->method('addColumn')->willReturnSelf();
        $table->method('addIndex')->willReturnSelf();
        $connection->method('newTable')->willReturn($table);
        $connection->method('dropTemporaryTable')->willThrowException(new \Zend_Db_Statement_Exception('gone away'));
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(3);

        $this->expectException(PreparationException::class);
        $this->expectExceptionMessage('orangecat_ib_cp_category_3: gone away');

        $this->snapshot()->categories($connection, $store);
    }

    /**
     * Snapshot over stub attributes and metadata
     *
     * @return StoreSnapshot
     */
    private function snapshot(): StoreSnapshot
    {
        $attribute = $this->createStub(AbstractAttribute::class);
        $attribute->method('getId')->willReturn(97);
        $config = $this->createStub(Config::class);
        $config->method('getAttribute')->willReturn($attribute);
        $metadata = $this->createStub(EntityMetadataInterface::class);
        $metadata->method('getLinkField')->willReturn('entity_id');
        $metadataPool = $this->createStub(MetadataPool::class);
        $metadataPool->method('getMetadata')->willReturn($metadata);
        return new StoreSnapshot($config, $metadataPool);
    }

    /**
     * Connection that logs the DDL and inserts it receives
     *
     * @param string[] $log
     * @param Select[]|null $selects selects returned in turn; one stub for every call when null
     * @return AdapterInterface
     */
    private function connection(array &$log, ?array $selects = null): AdapterInterface
    {
        $connection = $this->createStub(AdapterInterface::class);
        if ($selects === null) {
            $select = $this->createStub(Select::class);
            foreach (['from', 'join', 'joinInner', 'joinLeft', 'where', 'columns'] as $method) {
                $select->method($method)->willReturnSelf();
            }
            $connection->method('select')->willReturn($select);
        } else {
            $connection->method('select')->willReturnOnConsecutiveCalls(...$selects);
        }
        $connection->method('getTableName')->willReturnArgument(0);
        $connection->method('getIfNullSql')->willReturn('IFNULL()');
        $connection->method('quoteInto')->willReturn('quoted');
        $connection->method('newTable')->willReturnCallback(function (string $name): Table {
            $columns = [];
            $table = $this->createStub(Table::class);
            $table->method('getName')->willReturn($name);
            $table->method('addColumn')->willReturnCallback(function (string $column) use (&$columns, $table): Table {
                $columns[$column] = [];
                return $table;
            });
            $table->method('addIndex')->willReturnSelf();
            $table->method('getColumns')->willReturnCallback(function () use (&$columns): array {
                return $columns;
            });
            return $table;
        });
        $connection->method('dropTemporaryTable')->willReturnCallback(function (string $name) use (&$log): bool {
            $log[] = 'drop temporary ' . $name;
            return true;
        });
        $connection->method('createTemporaryTable')->willReturnCallback(
            function (Table $table) use (&$log): \Zend_Db_Statement_Interface {
                $log[] = sprintf(
                    'create temporary %s (%s)',
                    $table->getName(),
                    implode(',', array_keys($table->getColumns()))
                );
                return $this->createStub(\Zend_Db_Statement_Interface::class);
            }
        );
        $connection->method('insertFromSelect')->willReturnCallback(
            function (Select $select, string $table, array $columns) use (&$log): string {
                $log[] = sprintf('insert into %s (%s)', $table, implode(',', $columns));
                return 'INSERT';
            }
        );
        $connection->method('query')->willReturnCallback(function (string $sql) use (&$log) {
            $log[] = 'run ' . $sql;
            return $this->createStub(\Zend_Db_Statement_Interface::class);
        });
        return $connection;
    }
}
