<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Integration\Cron;

use Magento\Cron\Model\ConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Orangecat\IndexerBoost\Cron\CleanupRunLog;
use Orangecat\IndexerBoost\Model\ResourceModel\RunLog;
use PHPUnit\Framework\TestCase;

#[DbIsolation(true)]
class CleanupRunLogTest extends TestCase
{
    private const INDEXER_ID = 'zz_integration_cleanup';

    public function testJobIsScheduledDailyInTheDefaultGroup(): void
    {
        $jobs = Bootstrap::getObjectManager()->get(ConfigInterface::class)->getJobs();

        $job = $jobs['default']['orangecat_indexer_boost_run_log_cleanup'] ?? [];
        $this->assertSame(CleanupRunLog::class, $job['instance'] ?? null);
        $this->assertSame('execute', $job['method'] ?? null);
        $this->assertSame('30 3 * * *', $job['schedule'] ?? null);
    }

    #[Config('indexer_boost/monitor/retention_days', '30')]
    public function testRunsOlderThanTheRetentionAreDeleted(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $runLog = $objectManager->get(RunLog::class);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $runLog->saveRun(self::INDEXER_ID, 'full', $now->modify('-31 days'), 1.0, null);
        $runLog->saveRun(self::INDEXER_ID, 'full', $now->modify('-29 days'), 2.0, null);

        $objectManager->create(CleanupRunLog::class)->execute();

        $connection = $objectManager->get(ResourceConnection::class)->getConnection();
        $this->assertSame(
            ['2.000'],
            $connection->fetchCol(
                'SELECT duration_s FROM ' . $connection->getTableName(RunLog::TABLE) . ' WHERE indexer_id = ?',
                [self::INDEXER_ID]
            )
        );
    }
}
