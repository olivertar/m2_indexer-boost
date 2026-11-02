<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Monitor;

/**
 * Median of a list of durations
 */
class Median
{
    /**
     * Median value; null for an empty list
     *
     * @param float[] $values
     * @return float|null
     */
    public function calculate(array $values): ?float
    {
        if (!$values) {
            return null;
        }
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);
        return $count % 2 === 1
            ? (float)$values[$middle]
            : ((float)$values[$middle - 1] + (float)$values[$middle]) / 2;
    }
}
