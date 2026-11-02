<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Doctor\Check;

use Orangecat\IndexerBoost\Model\Doctor\Check\CronIndexerCheck;
use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use Orangecat\IndexerBoost\Test\Unit\Fake\WriteGuardAdapterTrait;
use PHPUnit\Framework\TestCase;

class CronIndexerCheckTest extends TestCase
{
    use WriteGuardAdapterTrait;

    public function testRecentSuccessIsOk(): void
    {
        $check = $this->check(['indexer_update_all_views' => '1', 'indexer_reindex_all_invalid' => '15']);

        $results = $check->check(new DoctorContext());

        $this->assertSame('CRON_INDEXER', $check->getCode());
        $this->assertSame(
            ['ok', 'indexer_update_all_views, indexer_reindex_all_invalid succeeded in the last 15 minutes'],
            [$results[0]->toArray()['severity'], $results[0]->toArray()['message']]
        );
        $this->assertStringContainsString("status = 'success'", $this->writeGuardQueries[0]);
        $this->assertNoWriteAttempts();
    }

    public function testNeverRunOrStaleIsCritical(): void
    {
        $check = $this->check(['indexer_reindex_all_invalid' => '42']);

        $this->assertSame(
            [
                'severity' => 'critical',
                'code' => 'CRON_INDEXER',
                'message' => 'Indexer cron jobs are not running: indexer_update_all_views never succeeded, '
                    . 'indexer_reindex_all_invalid last succeeded 42 min ago',
                'recommendation' => 'Without these jobs scheduled indexers never process their changelogs and '
                    . 'invalid indexers are never rebuilt, so the storefront keeps stale data.',
                'fix' => 'bin/magento cron:install (verify with crontab -l and bin/magento cron:run --group=index)',
                'metrics' => [
                    'minutes_since_success' => [
                        'indexer_update_all_views' => null,
                        'indexer_reindex_all_invalid' => 42,
                    ],
                ],
            ],
            $check->check(new DoctorContext())[0]->toArray()
        );
    }

    public function testJobsConfiguredWithNamedKeysInDiXmlAreBoundPositionally(): void
    {
        $binds = [];
        $recorder = static function (string $sql, string $method, array $bind) use (&$binds): array {
            $binds[] = $bind;
            return [];
        };
        $adapter = $this->createWriteGuardAdapter($recorder);
        $check = new CronIndexerCheck(
            new ReadOnlyConnection($this->createResourceConnection($adapter)),
            15,
            ['update_views' => 'indexer_update_all_views', 'reindex_invalid' => 'indexer_reindex_all_invalid']
        );

        $check->check(new DoctorContext());

        $this->assertSame([['indexer_update_all_views', 'indexer_reindex_all_invalid']], $binds);
    }

    /**
     * @param array<string,string> $minutes job_code => minutes since last success
     * @return CronIndexerCheck
     */
    private function check(array $minutes): CronIndexerCheck
    {
        $adapter = $this->createWriteGuardAdapter(static fn (): array => $minutes);
        return new CronIndexerCheck(
            new ReadOnlyConnection($this->createResourceConnection($adapter)),
            15,
            ['indexer_update_all_views', 'indexer_reindex_all_invalid']
        );
    }
}
