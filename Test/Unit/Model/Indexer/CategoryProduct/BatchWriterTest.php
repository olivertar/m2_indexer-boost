<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Indexer\CategoryProduct;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Orangecat\IndexerBoost\Model\Indexer\CategoryProduct\BatchWriter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BatchWriterTest extends TestCase
{
    private const TMP = 'catalog_category_product_index_store1_tmp';
    private const REPLICA = 'catalog_category_product_index_store1_replica';

    public function testTmpTableIsTruncatedBeforeEachBatchInsteadOfDeleted(): void
    {
        $statements = [];
        $connection = $this->connection();
        $connection->method('query')->willReturnCallback(function (string $sql) use (&$statements) {
            $statements[] = $sql;
            return $this->createStub(\Zend_Db_Statement_Interface::class);
        });
        // AdapterInterface::truncateTable() checks that the table exists, and that check misses TEMPORARY tables
        $connection->expects($this->never())->method('truncateTable');
        $connection->expects($this->never())->method('delete');

        (new BatchWriter())->write(
            $connection,
            $this->createStub(Select::class),
            'ccp.product_id IN (?)',
            [$this->createStub(Select::class), $this->createStub(Select::class)],
            self::TMP,
            self::REPLICA
        );

        $truncates = array_values(
            array_filter($statements, fn (string $sql): bool => str_starts_with($sql, 'TRUNCATE'))
        );
        $this->assertSame(['TRUNCATE TABLE `' . self::TMP . '`', 'TRUNCATE TABLE `' . self::TMP . '`'], $truncates);
    }

    public function testEveryBatchIsWrittenToTmpAndPublishedToReplicaLikeTheCore(): void
    {
        $log = [];
        $connection = $this->createStub(AdapterInterface::class);
        $tmpSelect = $this->createStub(Select::class);
        $tmpSelect->method('from')->willReturnCallback(function (string $table) use (&$log, $tmpSelect): Select {
            $log[] = 'select from ' . $table;
            return $tmpSelect;
        });
        $connection->method('select')->willReturn($tmpSelect);
        $connection->method('describeTable')->willReturnMap([
            [self::TMP, null, ['category_id' => [], 'product_id' => []]],
            [self::REPLICA, null, ['category_id' => [], 'product_id' => [], 'store_id' => []]],
        ]);
        $connection->method('quoteIdentifier')->willReturnCallback(fn (string $name): string => '`' . $name . '`');
        $batchQuery = $this->createStub(Select::class);
        $connection->method('fetchCol')->willReturnCallback(function (Select $query) use (&$log, $batchQuery): array {
            $log[] = 'fetch IDs' . ($query === $batchQuery ? '' : ' of another query');
            return [7, 8];
        });
        $basicSelect = $this->createStub(Select::class);
        $basicSelect->method('where')->willReturnCallback(
            function (string $condition, mixed $value, ?int $type) use (&$log, $basicSelect): Select {
                $log[] = sprintf('where %s [%s] %s', $condition, implode(',', $value), (string)$type);
                return $basicSelect;
            }
        );
        $connection->method('insertFromSelect')->willReturnCallback(
            function (Select $select, string $table, array $columns, int $mode) use (&$log, $tmpSelect): string {
                $log[] = sprintf(
                    'insert into %s (%s) %s from %s',
                    $table,
                    implode(',', $columns),
                    $mode === AdapterInterface::INSERT_ON_DUPLICATE ? 'on duplicate' : 'plain',
                    $select === $tmpSelect ? 'tmp' : 'batch select'
                );
                return 'INSERT ' . $table;
            }
        );
        $connection->method('query')->willReturnCallback(function (string $sql) use (&$log) {
            $log[] = 'run ' . $sql;
            return $this->createStub(\Zend_Db_Statement_Interface::class);
        });

        (new BatchWriter())->write(
            $connection,
            $basicSelect,
            'ccp.product_id IN (?)',
            [$batchQuery],
            self::TMP,
            self::REPLICA
        );

        $this->assertSame([
            'run TRUNCATE TABLE `' . self::TMP . '`',
            'fetch IDs',
            'where ccp.product_id IN (?) [7,8] ' . \Zend_Db::INT_TYPE,
            'insert into ' . self::TMP . ' (category_id,product_id) on duplicate from batch select',
            'run INSERT ' . self::TMP,
            'select from ' . self::TMP,
            'insert into ' . self::REPLICA . ' (category_id,product_id,store_id) on duplicate from tmp',
            'run INSERT ' . self::REPLICA,
        ], $log);
    }

    /**
     * Connection whose selects, table descriptions and insert statements are stubs
     *
     * @return AdapterInterface&MockObject
     */
    private function connection(): AdapterInterface&MockObject
    {
        $connection = $this->createMock(AdapterInterface::class);
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $connection->method('select')->willReturn($select);
        $connection->method('describeTable')->willReturn(['category_id' => [], 'product_id' => []]);
        $connection->method('fetchCol')->willReturn([1, 2]);
        $connection->method('insertFromSelect')->willReturn('INSERT');
        $connection->method('quoteIdentifier')->willReturnCallback(fn (string $name): string => '`' . $name . '`');
        return $connection;
    }
}
