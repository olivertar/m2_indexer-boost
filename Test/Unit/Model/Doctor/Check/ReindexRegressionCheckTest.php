<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Doctor\Check;

use Orangecat\IndexerBoost\Model\Config;
use Orangecat\IndexerBoost\Model\Doctor\Check\ReindexRegressionCheck;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use Orangecat\IndexerBoost\Model\Monitor\IndexerRunStats;
use Orangecat\IndexerBoost\Model\Monitor\RunStatsReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReindexRegressionCheckTest extends TestCase
{
    public function testDisabledMonitorIsReported(): void
    {
        $results = $this->findings([], false);

        $this->assertCount(1, $results);
        $this->assertSame('info', $results[0]['severity']);
        $this->assertSame('The passive reindex monitor is disabled', $results[0]['message']);
        $this->assertSame('bin/magento config:set indexer_boost/monitor/enabled 1', $results[0]['fix']);
    }

    public function testEmptyRunLogIsInfo(): void
    {
        $results = $this->findings([]);

        $this->assertCount(1, $results);
        $this->assertSame('info', $results[0]['severity']);
        $this->assertSame('No full reindex recorded yet in the passive run log', $results[0]['message']);
    }

    public function testIndexersWithLessThanSixSuccessfulRunsAreListedAsInfo(): void
    {
        $onlyFailures = new IndexerRunStats('catalogrule_rule', 2, 2, 0, 0, null, null, 0, null);

        $results = $this->findings([
            'catalog_product_price' => $this->stats('catalog_product_price', 900.0, 2, 100.0),
            'catalogrule_rule' => $onlyFailures,
        ]);

        $this->assertCount(1, $results);
        $this->assertSame('info', $results[0]['severity']);
        $this->assertSame(
            'Not enough history yet (6 successful full reindexes needed): '
            . 'catalog_product_price (3), catalogrule_rule (0)',
            $results[0]['message']
        );
        $this->assertSame(['catalog_product_price' => 3, 'catalogrule_rule' => 0], $results[0]['metrics']['runs']);
    }

    #[DataProvider('regressionProvider')]
    public function testRegressionNeedsMoreThanTwiceTheMedianAndMoreThanOneMinute(
        float $last,
        float $median,
        string $severity
    ): void {
        $stats = $this->stats('catalog_product_price', $last, 5, $median);

        $results = $this->findings(['catalog_product_price' => $stats]);

        $this->assertCount(1, $results);
        $this->assertSame($severity, $results[0]['severity']);
    }

    /**
     * @return array<string,array{float,float,string}>
     */
    public static function regressionProvider(): array
    {
        return [
            '3.2x and 400 s' => [400.0, 125.0, 'warn'],
            '2.03x and 61 s' => [61.0, 30.0, 'warn'],
            '2.5x but 50 s' => [50.0, 20.0, 'ok'],
            'exactly 2x' => [250.0, 125.0, 'ok'],
            'faster than usual' => [90.0, 125.0, 'ok'],
        ];
    }

    public function testRegressionFindingAndOkSummary(): void
    {
        $results = $this->findings([
            'catalog_product_price' => $this->stats('catalog_product_price', 400.0, 5, 125.0),
            'catalogsearch_fulltext' => $this->stats('catalogsearch_fulltext', 80.0, 5, 75.5),
        ]);

        $this->assertCount(1, $results);
        $this->assertSame(
            [
                'severity' => 'warn',
                'code' => 'REINDEX_REGRESSION',
                'message' => 'catalog_product_price: last full reindex (2026-10-31 03:00:00 UTC) took 400 s, '
                    . '3.2x the median of the previous 5 (125 s)',
                'recommendation' => 'Look for what changed before that run: catalog imports, new or changed catalog '
                    . 'rules, websites or customer groups, MySQL configuration or statistics, custom indexer code.',
                'fix' => 'bin/magento indexer-boost:doctor --only=CATALOG_SIZE,CATALOG_RULES,TABLE_STATS,MYSQL_VARS',
                'metrics' => ['indexer_id' => 'catalog_product_price', 'last_s' => 400.0, 'median_s' => 125.0,
                    'ratio' => 3.2],
            ],
            $results[0]
        );

        $ok = $this->findings(['catalogsearch_fulltext' => $this->stats('catalogsearch_fulltext', 80.0, 5, 75.5)]);
        $this->assertSame('No reindex regression (1 indexer compared with its usual duration)', $ok[0]['message']);
        $this->assertSame(
            ['catalogsearch_fulltext' => ['last_s' => 80.0, 'median_s' => 75.5]],
            $ok[0]['metrics']['indexers']
        );
    }

    /**
     * @param IndexerRunStats[] $stats
     * @param bool $monitorEnabled
     * @return array<int,array<string,mixed>>
     */
    private function findings(array $stats, bool $monitorEnabled = true): array
    {
        $reader = $this->createStub(RunStatsReader::class);
        $reader->method('getStats')->willReturn($stats);
        $config = $this->createStub(Config::class);
        $config->method('isMonitorEnabled')->willReturn($monitorEnabled);
        $check = new ReindexRegressionCheck($reader, $config, 5, 2.0, 60.0);

        return array_map(static fn ($result): array => $result->toArray(), $check->check(new DoctorContext()));
    }

    /**
     * Stats with only successful full reindexes
     *
     * @param string $indexerId
     * @param float $last seconds of the last full reindex
     * @param int $previousRuns
     * @param float|null $median
     * @return IndexerRunStats
     */
    private function stats(string $indexerId, float $last, int $previousRuns, ?float $median): IndexerRunStats
    {
        return new IndexerRunStats(
            $indexerId,
            $previousRuns + 1,
            0,
            0,
            0,
            '2026-10-31 03:00:00',
            $last,
            $previousRuns,
            $median
        );
    }
}
