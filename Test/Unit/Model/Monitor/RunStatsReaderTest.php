<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Monitor;

use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;
use Orangecat\IndexerBoost\Model\Monitor\IndexerRunStats;
use Orangecat\IndexerBoost\Model\Monitor\Median;
use Orangecat\IndexerBoost\Model\Monitor\RunStatsReader;
use Orangecat\IndexerBoost\Test\Unit\Fake\WriteGuardAdapterTrait;
use PHPUnit\Framework\TestCase;

class RunStatsReaderTest extends TestCase
{
    use WriteGuardAdapterTrait;

    /**
     * @var array<int,array<mixed>>
     */
    private array $binds = [];

    public function testStatsPerIndexerWithLastFullRunAndMedianOfThePreviousOnes(): void
    {
        $stats = $this->stats(
            [
                ['indexer_id' => 'catalog_product_price', 'kind' => 'full', 'runs' => '8', 'failures' => '1'],
                ['indexer_id' => 'catalog_product_price', 'kind' => 'changes', 'runs' => '40', 'failures' => '0'],
                ['indexer_id' => 'catalogsearch_fulltext', 'kind' => 'changes', 'runs' => '3', 'failures' => '2'],
            ],
            [
                // newest first, as ranked by the query
                $this->rankedRun('2026-10-31 01:00:00', '300.5'),
                $this->rankedRun('2026-10-30 01:00:00', '100.000'),
                $this->rankedRun('2026-10-29 01:00:00', '140.000'),
                $this->rankedRun('2026-10-28 01:00:00', '120.000'),
            ]
        );

        $this->assertSame(['catalog_product_price', 'catalogsearch_fulltext'], array_keys($stats));
        $price = $stats['catalog_product_price'];
        $this->assertSame(
            [
                'indexer_id' => 'catalog_product_price',
                'full_runs' => 8,
                'full_failures' => 1,
                'changes_runs' => 40,
                'changes_failures' => 0,
                'last_full_started_at' => '2026-10-31 01:00:00',
                'last_full_duration_s' => 300.5,
                'previous_full_runs' => 3,
                'previous_full_median_s' => 120.0,
            ],
            $price->toArray()
        );
        $this->assertSame(
            [
                'indexer_id' => 'catalogsearch_fulltext',
                'full_runs' => 0,
                'full_failures' => 0,
                'changes_runs' => 3,
                'changes_failures' => 2,
                'last_full_started_at' => null,
                'last_full_duration_s' => null,
                'previous_full_runs' => 0,
                'previous_full_median_s' => null,
            ],
            $stats['catalogsearch_fulltext']->toArray()
        );
        $this->assertContainsOnlyInstancesOf(IndexerRunStats::class, $stats);
        $this->assertNoWriteAttempts();
    }

    public function testOnlySuccessfulFullRunsAreRankedAndLimitedToLastPlusPrevious(): void
    {
        $this->stats([], []);

        $rankedSql = $this->writeGuardQueries[1];
        $this->assertStringContainsString('ROW_NUMBER() OVER (PARTITION BY indexer_id', $rankedSql);
        $this->assertStringContainsString("WHERE kind = 'full' AND failed = 0", $rankedSql);
        $this->assertStringContainsString('FROM pfx_orangecat_indexer_boost_run_log', $rankedSql);
        $this->assertSame([[], [6]], $this->binds);
    }

    public function testEmptyLog(): void
    {
        $this->assertSame([], $this->stats([], []));
    }

    /**
     * Ranked full run of catalog_product_price
     *
     * @param string $startedAt
     * @param string $duration
     * @return array<string,string>
     */
    private function rankedRun(string $startedAt, string $duration): array
    {
        return ['indexer_id' => 'catalog_product_price', 'started_at' => $startedAt, 'duration_s' => $duration];
    }

    /**
     * @param array<int,array<string,string>> $counts
     * @param array<int,array<string,string>> $recentFull
     * @return array<string,IndexerRunStats>
     */
    private function stats(array $counts, array $recentFull): array
    {
        $adapter = $this->createWriteGuardAdapter(
            function (string $sql, string $method, array $bind) use ($counts, $recentFull): array {
                $this->binds[] = $bind;
                return str_contains($sql, 'ROW_NUMBER') ? $recentFull : $counts;
            }
        );
        $reader = new RunStatsReader(
            new ReadOnlyConnection($this->createResourceConnection($adapter)),
            new Median(),
            5
        );
        return $reader->getStats();
    }
}
