<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Indexer;

use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Empties the TEMPORARY work tables of the core indexers between batches
 *
 * The core empties them with DELETE. InnoDB keeps the deleted rows as delete-marked records, so every batch scans the
 * rows of all the previous ones and a full reindex grows with the square of the batch count. TRUNCATE recreates the
 * table. Only for full reindexes, outside a transaction: TRUNCATE commits implicitly.
 */
class TemporaryTable
{
    /**
     * Empty a TEMPORARY table
     *
     * Not AdapterInterface::truncateTable(): it first checks that the table exists, and misses TEMPORARY tables.
     *
     * @param AdapterInterface $connection the connection that created the table
     * @param string $tableName
     * @return void
     */
    public function truncate(AdapterInterface $connection, string $tableName): void
    {
        // phpcs:ignore Magento2.SQL.RawQuery
        $connection->query('TRUNCATE TABLE ' . $connection->quoteIdentifier($tableName));
    }
}
