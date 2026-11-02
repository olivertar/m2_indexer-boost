<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Integration\Monitor;

use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Orangecat\IndexerBoost\Model\Monitor\RunStatsReader;
use Orangecat\IndexerBoost\Model\ResourceModel\RunLog;
use PHPUnit\Framework\TestCase;

/**
 * Run log statistics on the real database server (window function, grouping, decimals)
 */
#[DbIsolation(true)]
class RunStatsReaderTest extends TestCase
{
    private const INDEXER_ID = 'zz_integration_stats';

    public function testLastFullReindexAndMedianOfThePreviousOnes(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $runLog = $objectManager->get(RunLog::class);
        $start = new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC'));
        // Oldest first: 1000 s falls out of the 5 previous runs, the failed run is not a duration sample
        foreach ([1000.0, 10.0, 20.0, 30.0, 40.0, 50.0] as $hour => $seconds) {
            $runLog->saveRun(self::INDEXER_ID, 'full', $start->modify("+{$hour} hours"), $seconds, null);
        }
        $runLog->saveRun(self::INDEXER_ID, 'full', $start->modify('+10 hours'), 5.0, 'Deadlock found');
        $runLog->saveRun(self::INDEXER_ID, 'full', $start->modify('+11 hours'), 99.5, null);
        $runLog->saveRun(self::INDEXER_ID, 'changes', $start->modify('+12 hours'), 1.25, null);

        $stats = $objectManager->create(RunStatsReader::class)->getStats()[self::INDEXER_ID];

        $this->assertSame(
            [
                'indexer_id' => self::INDEXER_ID,
                'full_runs' => 8,
                'full_failures' => 1,
                'changes_runs' => 1,
                'changes_failures' => 0,
                'last_full_started_at' => '2026-01-01 11:00:00',
                'last_full_duration_s' => 99.5,
                'previous_full_runs' => 5,
                'previous_full_median_s' => 30.0,
            ],
            $stats->toArray()
        );
    }
}
