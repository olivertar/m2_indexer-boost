<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Monitor;

/**
 * Wall clock for run start times and monotonic clock for durations
 */
class Clock
{
    /**
     * Current time in UTC
     *
     * @return \DateTimeImmutable
     */
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * Monotonic time in seconds, only meaningful as a difference between two calls
     *
     * @return float
     */
    public function monotonic(): float
    {
        return hrtime(true) / 1e9;
    }
}
