<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Indexer\CategoryProduct;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;

/**
 * Batch loop of the full catalog_category_product reindex, emptying the tmp table with TRUNCATE
 *
 * Same steps as the private Full::reindexCategoriesBySelect() of the core. The core empties the TEMPORARY tmp table
 * with DELETE before every batch; InnoDB keeps the deleted rows as delete-marked records, so every batch scans the
 * rows of all the previous ones and the reindex grows with the square of the batch count. TRUNCATE recreates the
 * table, so every batch costs the same.
 */
class BatchWriter
{
    /**
     * Write the rows of every batch into the tmp table and publish them to the replica table
     *
     * @param AdapterInterface $connection
     * @param Select $basicSelect rows of every product, restricted to the batch by $whereCondition
     * @param string $whereCondition condition with one placeholder for the product IDs of the batch
     * @param iterable<Select> $batchQueries one select of product IDs per batch
     * @param string $tmpTable
     * @param string $replicaTable
     * @return void
     */
    public function write(
        AdapterInterface $connection,
        Select $basicSelect,
        string $whereCondition,
        iterable $batchQueries,
        string $tmpTable,
        string $replicaTable
    ): void {
        $tmpColumns = array_keys($connection->describeTable($tmpTable));
        $replicaColumns = array_keys($connection->describeTable($replicaTable));
        // Not AdapterInterface::truncateTable(): it first checks that the table exists, and misses TEMPORARY tables
        // phpcs:ignore Magento2.SQL.RawQuery
        $truncate = 'TRUNCATE TABLE ' . $connection->quoteIdentifier($tmpTable);
        foreach ($batchQueries as $query) {
            $connection->query($truncate);
            $resultSelect = clone $basicSelect;
            $resultSelect->where($whereCondition, $connection->fetchCol($query), \Zend_Db::INT_TYPE);
            $connection->query(
                $connection->insertFromSelect(
                    $resultSelect,
                    $tmpTable,
                    $tmpColumns,
                    AdapterInterface::INSERT_ON_DUPLICATE
                )
            );
            $connection->query(
                $connection->insertFromSelect(
                    $connection->select()->from($tmpTable),
                    $replicaTable,
                    $replicaColumns,
                    AdapterInterface::INSERT_ON_DUPLICATE
                )
            );
        }
    }
}
