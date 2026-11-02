<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Indexer;

use Magento\Framework\DB\Select;
use Orangecat\IndexerBoost\Model\Indexer\JoinOrder;
use PHPUnit\Framework\TestCase;

class JoinOrderTest extends TestCase
{
    public function testTurnsTheJoinOfTheAliasIntoAStraightJoin(): void
    {
        $from = [
            'e' => ['joinType' => Select::FROM, 'joinCondition' => null],
            'cg' => ['joinType' => Select::CROSS_JOIN, 'joinCondition' => null],
            'cgw' => ['joinType' => Select::LEFT_JOIN, 'joinCondition' => 'cgw.id = cg.id'],
        ];
        $select = $this->createMock(Select::class);
        $select->method('getPart')->with(Select::FROM)->willReturn($from);
        $expected = $from;
        $expected['cg']['joinType'] = 'straight_join';
        $select->expects($this->once())->method('setPart')->with(Select::FROM, $expected)->willReturnSelf();

        $this->assertTrue((new JoinOrder())->after($select, 'cg'));
    }
}
