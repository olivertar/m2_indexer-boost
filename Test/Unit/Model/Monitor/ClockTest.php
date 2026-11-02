<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Monitor;

use Orangecat\IndexerBoost\Model\Monitor\Clock;
use PHPUnit\Framework\TestCase;

class ClockTest extends TestCase
{
    public function testNowIsUtc(): void
    {
        $this->assertSame('UTC', (new Clock())->now()->getTimezone()->getName());
    }

    public function testMonotonicNeverGoesBack(): void
    {
        $clock = new Clock();
        $first = $clock->monotonic();

        $this->assertGreaterThanOrEqual($first, $clock->monotonic());
    }
}
