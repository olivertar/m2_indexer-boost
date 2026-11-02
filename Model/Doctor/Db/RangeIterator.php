<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Doctor\Db;

/**
 * Split a table into consecutive [from, to] ranges of an integer key so heavy queries never scan it at once
 */
class RangeIterator
{
    /**
     * @param ReadOnlyConnection $connection
     */
    public function __construct(
        private readonly ReadOnlyConnection $connection
    ) {
    }

    /**
     * Ranges of $step key values from MIN($column) to MAX($column); nothing for an empty table
     *
     * @param string $table resolved table name
     * @param string $column integer key column, ideally the first column of an index
     * @param int $step
     * @return \Generator<int,array{int,int}>
     * @throws \InvalidArgumentException
     */
    public function ranges(string $table, string $column, int $step): \Generator
    {
        if ($step < 1) {
            throw new \InvalidArgumentException('Range step must be at least 1');
        }
        $bounds = $this->connection->fetchRow(
            sprintf('SELECT MIN(%1$s) AS min_id, MAX(%1$s) AS max_id FROM %2$s', $column, $table)
        ) ?: [];
        if (($bounds['min_id'] ?? null) === null) {
            return;
        }

        $max = (int)$bounds['max_id'];
        for ($from = (int)$bounds['min_id']; $from <= $max; $from += $step) {
            yield [$from, min($from + $step - 1, $max)];
        }
    }
}
