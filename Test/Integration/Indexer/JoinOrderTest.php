<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Integration\Indexer;

use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Helper\Bootstrap;
use Orangecat\IndexerBoost\Model\Indexer\JoinOrder;
use PHPUnit\Framework\TestCase;

/**
 * The join order hint reaches the SQL that Magento renders, unlike Select::useStraightJoin()
 */
class JoinOrderTest extends TestCase
{
    public function testRendersAndRunsAStraightJoinForTheAliasOnly(): void
    {
        $connection = Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection();
        $select = $connection->select()
            ->from(['e' => $connection->getTableName('catalog_product_entity')], ['count' => 'COUNT(*)'])
            ->joinInner(['cg' => $connection->getTableName('customer_group')], '', [])
            ->joinLeft(
                ['cgw' => $connection->getTableName('customer_group_excluded_website')],
                'cgw.customer_group_id = cg.customer_group_id',
                []
            );
        $expected = (int)$connection->fetchOne($select);

        $this->assertTrue((new JoinOrder())->after($select, 'cg'));
        $this->assertFalse((new JoinOrder())->after($select, 'e'));

        $sql = $select->assemble();
        $this->assertStringContainsString('STRAIGHT_JOIN `' . $connection->getTableName('customer_group') . '`', $sql);
        $this->assertStringContainsString('LEFT JOIN', $sql);
        $this->assertSame($expected, (int)$connection->fetchOne($select));
    }
}
