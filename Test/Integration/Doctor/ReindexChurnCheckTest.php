<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Integration\Doctor;

use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Orangecat\IndexerBoost\Model\Doctor\Check\ReindexChurnCheck;
use Orangecat\IndexerBoost\Model\Doctor\CheckResult;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use Orangecat\IndexerBoost\Model\ResourceModel\RunLog;
use PHPUnit\Framework\TestCase;

/**
 * Overlapping full reindexes on the real database server: whole-second starts, sub-second runs
 */
#[DbIsolation(true)]
class ReindexChurnCheckTest extends TestCase
{
    public function testOnlyRunsThatProvablyOverlapAreReported(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $resource = $objectManager->get(ResourceConnection::class);
        $resource->getConnection()->delete($resource->getTableName(RunLog::TABLE));
        $runLog = $objectManager->get(RunLog::class);
        $base = new \DateTimeImmutable(gmdate('Y-m-d H:00:00', time() - 3600), new \DateTimeZone('UTC'));
        // One indexer:reindex after the other within the same second: sequential
        $runLog->saveRun('zz_first', 'full', $base, 0.4, null);
        $runLog->saveRun('zz_second', 'full', $base, 0.3, null);
        // A run inside a long one, recorded first because it ends first
        $runLog->saveRun('zz_inner', 'full', $base->modify('+20 seconds'), 2.0, null);
        $runLog->saveRun('zz_long', 'full', $base->modify('+10 seconds'), 30.0, null);
        // Starts in the second the previous run may have ended: not provable
        $runLog->saveRun('zz_edge', 'full', $base->modify('+100 seconds'), 1.5, null);
        $runLog->saveRun('zz_next', 'full', $base->modify('+101 seconds'), 0.2, null);

        $overlaps = array_values(array_filter(
            $objectManager->create(ReindexChurnCheck::class)->check(new DoctorContext()),
            static fn (CheckResult $result): bool => isset($result->toArray()['metrics']['overlaps'])
        ));

        $this->assertCount(1, $overlaps);
        $this->assertSame(['overlaps' => 1], $overlaps[0]->toArray()['metrics']);
        $this->assertStringContainsString('zz_long with zz_inner', $overlaps[0]->toArray()['message']);
    }
}
