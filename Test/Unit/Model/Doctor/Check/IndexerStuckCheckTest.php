<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Doctor\Check;

use Orangecat\IndexerBoost\Model\Doctor\Check\IndexerStuckCheck;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use Orangecat\IndexerBoost\Model\Repair\StuckIndexer;
use Orangecat\IndexerBoost\Model\Repair\StuckIndexerDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IndexerStuckCheckTest extends TestCase
{
    public function testNoLongWorkingIndexerIsOk(): void
    {
        $results = $this->findings([]);

        $this->assertCount(1, $results);
        $this->assertSame(['ok', 'INDEXER_STUCK', 'No indexer left in "working"'], [
            $results[0]['severity'],
            $results[0]['code'],
            $results[0]['message'],
        ]);
    }

    public function testStuckIndexersAreCriticalWithRepairFix(): void
    {
        $results = $this->findings([
            new StuckIndexer('catalog_product_price', 185, StuckIndexer::STUCK),
            new StuckIndexer('inventory', 2900, StuckIndexer::STUCK),
        ]);

        $this->assertCount(1, $results);
        $this->assertSame('critical', $results[0]['severity']);
        $this->assertSame(
            'Stuck indexers (working, lock free, no running statement on their tables): '
            . 'catalog_product_price (185 min), inventory (2900 min)',
            $results[0]['message']
        );
        $this->assertSame('bin/magento indexer-boost:doctor --repair', $results[0]['fix']);
        $this->assertSame(['catalog_product_price' => 185, 'inventory' => 2900], $results[0]['metrics']['minutes']);
    }

    #[DataProvider('verdictProvider')]
    public function testOtherVerdicts(string $verdict, string $severity, string $message, string $fix): void
    {
        $results = $this->findings([new StuckIndexer('catalog_product_price', 185, $verdict, 'INSERT INTO x')]);

        $this->assertCount(1, $results);
        $this->assertSame(
            [$severity, $message . ': catalog_product_price (185 min)', $fix],
            [$results[0]['severity'], $results[0]['message'], $results[0]['fix']]
        );
    }

    /**
     * @return array<string,array{string,string,string,string}>
     */
    public static function verdictProvider(): array
    {
        return [
            'doubtful' => [
                StuckIndexer::DOUBTFUL,
                'warn',
                'Probably stuck indexers (working, lock free; running statements not visible without the PROCESS '
                . 'privilege)',
                'bin/magento indexer-boost:doctor --repair',
            ],
            'active' => [
                StuckIndexer::ACTIVE,
                'info',
                'Indexers working without lock but still writing their tables (multi-process reindex)',
                '',
            ],
            'running' => [
                StuckIndexer::RUNNING,
                'info',
                'Long reindex in progress (lock held)',
                '',
            ],
            'unverifiable' => [
                StuckIndexer::UNVERIFIABLE,
                'info',
                'Indexers working for a long time; stuck or running cannot be told without the application lock',
                "app/etc/env.php: 'indexer' => ['use_application_lock' => true]",
            ],
        ];
    }

    public function testFindingsAreGroupedFromWorstToBest(): void
    {
        $results = $this->findings([
            new StuckIndexer('catalogrule_rule', 300, StuckIndexer::RUNNING),
            new StuckIndexer('catalog_product_price', 185, StuckIndexer::STUCK),
            new StuckIndexer('inventory', 200, StuckIndexer::DOUBTFUL),
        ]);

        $this->assertSame(['critical', 'warn', 'info'], array_column($results, 'severity'));
    }

    /**
     * @param StuckIndexer[] $stuck
     * @return array<int,array<string,mixed>>
     */
    private function findings(array $stuck): array
    {
        $byId = [];
        foreach ($stuck as $indexer) {
            $byId[$indexer->indexerId] = $indexer;
        }
        $detector = $this->createStub(StuckIndexerDetector::class);
        $detector->method('detect')->willReturn($byId);

        return array_map(
            static fn ($result): array => $result->toArray(),
            (new IndexerStuckCheck($detector))->check(new DoctorContext())
        );
    }
}
