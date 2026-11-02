<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Indexer;

use Magento\Framework\DB\Select;

/**
 * Forces MariaDB/MySQL to read one joined table after all the tables written before it
 *
 * Select::useStraightJoin() does nothing in Magento: SelectRenderer has no renderer for that part. The join type, by
 * contrast, is rendered as written, so a "STRAIGHT_JOIN" join fixes the order at that table only and leaves the rest
 * of the plan to the optimizer.
 */
class JoinOrder
{
    private const STRAIGHT_JOIN = 'straight_join';

    /**
     * Turn the join of the alias into a STRAIGHT_JOIN
     *
     * @param Select $select
     * @param string $alias
     * @return bool false when the select has no join with that alias
     */
    public function after(Select $select, string $alias): bool
    {
        $from = $select->getPart(Select::FROM);
        if (!isset($from[$alias]) || $from[$alias]['joinType'] === Select::FROM) {
            return false;
        }
        $from[$alias]['joinType'] = self::STRAIGHT_JOIN;
        $select->setPart(Select::FROM, $from);
        return true;
    }
}
