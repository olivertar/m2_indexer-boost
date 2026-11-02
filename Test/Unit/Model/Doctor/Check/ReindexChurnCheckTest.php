<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Doctor\Check;

use Orangecat\IndexerBoost\Model\Config;
use Orangecat\IndexerBoost\Model\Doctor\Check\ReindexChurnCheck;
use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use Orangecat\IndexerBoost\Test\Unit\Fake\WriteGuardAdapterTrait;
use PHPUnit\Framework\TestCase;

class ReindexChurnCheckTest extends TestCase
{
    use WriteGuardAdapterTrait;

    /**
     * Bound values per query kind
     *
     * @var array<string,array<mixed>>
     */
    private array $binds = [];

    public function testQuietDayIsOk(): void
    {
        $check = $this->check();

        $results = $check->check(new DoctorContext());

        $this->assertSame('REINDEX_CHURN', $check->getCode());
        $this->assertFalse($check->isHeavy());
        $this->assertCount(1, $results);
        $this->assertSame(
            [
                'severity' => 'ok',
                'code' => 'REINDEX_CHURN',
                'message' => 'In the last 24 h no indexer repeated its full reindex, no full reindexes overlapped '
                    . 'and the index cron jobs kept up',
                'recommendation' => '',
                'fix' => '',
                'metrics' => [],
            ],
            $results[0]->toArray()
        );
        $this->assertNoWriteAttempts();
    }

    public function testRepeatedFullReindexesAreWarn(): void
    {
        $check = $this->check(['catalog_product_attribute' => 6, 'catalogrule_rule' => 3]);

        $results = $check->check(new DoctorContext());

        $this->assertCount(1, $results);
        $this->assertSame(
            [
                'severity' => 'warn',
                'code' => 'REINDEX_CHURN',
                'message' => 'Repeated full reindexes in the last 24 h: catalog_product_attribute (6), '
                    . 'catalogrule_rule (3)',
                'recommendation' => 'Each full reindex rebuilds the whole index, and invalid indexers are rebuilt '
                    . 'one after another. Repeated ones usually come from invalidations: imports with indexers in '
                    . '"Update on Save" (see INDEXER_MODE), changes to attributes, websites, customer groups or '
                    . 'catalog rules, or scripts that run indexer:reindex.',
                'fix' => 'Put the indexers on "Update by Schedule" and look for indexer:reindex calls in crontabs, '
                    . 'deployment and import scripts',
                'metrics' => ['full_runs' => ['catalog_product_attribute' => 6, 'catalogrule_rule' => 3]],
            ],
            $results[0]->toArray()
        );
        $this->assertSame([24, 3], $this->bindOf('HAVING'));
    }

    public function testOverlappingFullReindexesAreWarnWithTheFirstFiveListed(): void
    {
        $overlaps = [];
        foreach (range(1, 6) as $hour) {
            $overlaps[] = [
                'indexer_id' => 'catalog_product_price',
                'overlapping_indexer' => 'catalogsearch_fulltext',
                'started_at' => sprintf('2026-11-02 0%d:00:00', $hour),
            ];
        }
        $check = $this->check([], $overlaps);

        $result = $check->check(new DoctorContext())[0]->toArray();

        $this->assertSame('warn', $result['severity']);
        $this->assertSame(
            'Full reindexes ran at the same time 6 times in the last 24 h: catalog_product_price with '
            . 'catalogsearch_fulltext at 2026-11-02 01:00:00 UTC, catalog_product_price with catalogsearch_fulltext '
            . 'at 2026-11-02 02:00:00 UTC, catalog_product_price with catalogsearch_fulltext at 2026-11-02 03:00:00 '
            . 'UTC, catalog_product_price with catalogsearch_fulltext at 2026-11-02 04:00:00 UTC, '
            . 'catalog_product_price with catalogsearch_fulltext at 2026-11-02 05:00:00 UTC and 1 more',
            $result['message']
        );
        $this->assertSame(
            'Concurrent full reindexes compete for the same database, so each one takes longer and they can '
            . 'block each other. Usually indexer:reindex runs by hand or from a script while cron is reindexing.',
            $result['recommendation']
        );
        $this->assertSame(
            'Leave full reindexes to cron, or pause it first (bin/magento cron:remove, then cron:install); enable '
            . 'the indexer application lock (see APPLICATION_LOCK)',
            $result['fix']
        );
        $this->assertSame(['overlaps' => 6], $result['metrics']);
        $this->assertSame([24], $this->bindOf('overlapping'));
    }

    public function testIndexCronJobsFallingBehindAreWarn(): void
    {
        $check = $this->check([], [], [
            ['job_code' => 'indexer_update_all_views', 'missed' => '30', 'errors' => '2', 'longest_s' => '412'],
            ['job_code' => 'indexer_reindex_all_invalid', 'missed' => '0', 'errors' => '0', 'longest_s' => '60'],
        ]);

        $result = $check->check(new DoctorContext())[0]->toArray();

        $this->assertSame(
            [
                'severity' => 'warn',
                'code' => 'REINDEX_CHURN',
                'message' => 'Index cron jobs fell behind in the last 24 h: indexer_update_all_views took up to '
                    . '412 s (scheduled every 60 s), 30 missed, 2 failed',
                'recommendation' => 'A run longer than the cron interval makes the next ones wait or be missed, '
                    . 'so changes pile up in the changelogs and the indexers catch up one after another, typically '
                    . 'after an import.',
                'fix' => 'Speed up the slowest indexer (see REINDEX_REGRESSION, MVIEW_BACKLOG and BATCH_SIZE) and '
                    . 'run large imports off-peak; check var/log/cron.log for the failed runs',
                'metrics' => [
                    'cron_jobs' => [
                        'indexer_update_all_views' => ['missed' => 30, 'failed' => 2, 'longest_s' => 412],
                    ],
                ],
            ],
            $result
        );
        $this->assertSame(['indexer_update_all_views', 'indexer_reindex_all_invalid', 24], $this->bindOf('cron'));
    }

    public function testWithoutThePassiveMonitorOnlyTheCronJobsAreChecked(): void
    {
        $check = $this->check(['catalog_product_attribute' => 6], [], [], false);

        $result = $check->check(new DoctorContext())[0]->toArray();

        $this->assertSame('ok', $result['severity']);
        $this->assertSame(
            'In the last 24 h the index cron jobs kept up. Repeated and overlapping full reindexes were not checked: '
            . 'the passive reindex monitor is disabled',
            $result['message']
        );
        $this->assertNull($this->bindOf('HAVING'));
        $this->assertNull($this->bindOf('overlapping'));
    }

    /**
     * Values bound to the first query that contains the given text
     *
     * @param string $needle
     * @return array<mixed>|null
     */
    private function bindOf(string $needle): ?array
    {
        return $this->binds[$needle] ?? null;
    }

    /**
     * Check over a fake database
     *
     * @param array<string,int> $fullRuns indexer_id => full reindexes in the window
     * @param array<int,array<string,string>> $overlaps rows of overlapping full reindexes
     * @param array<int,array<string,int|string>> $cronJobs rows per index cron job
     * @param bool $monitorEnabled
     * @return ReindexChurnCheck
     */
    private function check(
        array $fullRuns = [],
        array $overlaps = [],
        array $cronJobs = [],
        bool $monitorEnabled = true
    ): ReindexChurnCheck {
        $adapter = $this->createWriteGuardAdapter(
            function (string $sql, string $method, array $bind) use ($fullRuns, $overlaps, $cronJobs): array {
                $kind = match (true) {
                    str_contains($sql, 'HAVING') => 'HAVING',
                    str_contains($sql, 'overlapping') => 'overlapping',
                    default => 'cron',
                };
                $this->binds[$kind] ??= $bind;
                return ['HAVING' => $fullRuns, 'overlapping' => $overlaps, 'cron' => $cronJobs][$kind];
            }
        );
        $config = $this->createStub(Config::class);
        $config->method('isMonitorEnabled')->willReturn($monitorEnabled);

        return new ReindexChurnCheck(
            new ReadOnlyConnection($this->createResourceConnection($adapter)),
            $config,
            24,
            3,
            ['indexer_update_all_views', 'indexer_reindex_all_invalid'],
            60
        );
    }
}
