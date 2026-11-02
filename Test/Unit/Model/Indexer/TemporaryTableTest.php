<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Indexer;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Orangecat\IndexerBoost\Model\Indexer\TemporaryTable;
use PHPUnit\Framework\TestCase;

class TemporaryTableTest extends TestCase
{
    public function testTruncateRunsAPlainTruncateBecauseTheAdapterOneMissesTemporaryTables(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('quoteIdentifier')->willReturnCallback(fn (string $name): string => '`' . $name . '`');
        $connection->expects($this->never())->method('truncateTable');
        $connection->expects($this->once())->method('query')->with('TRUNCATE TABLE `catalog_product_index_eav_temp`');

        (new TemporaryTable())->truncate($connection, 'catalog_product_index_eav_temp');
    }
}
