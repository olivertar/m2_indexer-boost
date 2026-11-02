<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Doctor\Check;

use Orangecat\IndexerBoost\Model\Doctor\Catalog\CatalogSize;
use Orangecat\IndexerBoost\Model\Doctor\Catalog\CatalogSizeProvider;
use Orangecat\IndexerBoost\Model\Doctor\Check\ParallelThreadsCheck;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use Orangecat\IndexerBoost\Model\Doctor\System\SystemInfo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ParallelThreadsCheckTest extends TestCase
{
    public function testThreadsNotSetOnLargeCatalogIsWarnSuggestingCoresMinusOne(): void
    {
        $check = $this->check(null, true, 8, 5000);

        $results = $check->check(new DoctorContext());

        $this->assertSame('PARALLEL_THREADS', $check->getCode());
        $this->assertCount(1, $results);
        $this->assertSame(
            [
                'severity' => 'warn',
                'code' => 'PARALLEL_THREADS',
                'message' => 'MAGE_INDEXER_THREADS_COUNT is not set: full reindex runs in a single process '
                    . '(8 CPU cores available, pcntl enabled)',
                'recommendation' => 'Indexers that support it (price, catalog search, category products) split '
                    . 'a full reindex across processes. Set the variable in the environment of the shell and of '
                    . 'the cron user, then measure.',
                'fix' => 'export MAGE_INDEXER_THREADS_COUNT=7',
                'metrics' => ['threads' => null, 'cpu_cores' => 8, 'pcntl' => true],
            ],
            $results[0]->toArray()
        );
    }

    public function testThreadsWithinCoresAndPcntlIsOk(): void
    {
        $results = $this->check('4', true, 8, 5000)->check(new DoctorContext());

        $this->assertSame(['ok', 'MAGE_INDEXER_THREADS_COUNT=4 with pcntl enabled (8 CPU cores)'], [
            $results[0]->toArray()['severity'],
            $results[0]->toArray()['message'],
        ]);
    }

    public function testMoreThreadsThanCoresIsWarn(): void
    {
        $results = $this->check('16', true, 8, 100)->check(new DoctorContext());

        $this->assertSame(
            [
                'severity' => 'warn',
                'code' => 'PARALLEL_THREADS',
                'message' => 'MAGE_INDEXER_THREADS_COUNT=16 is higher than the 8 CPU cores available',
                'recommendation' => 'More processes than cores compete for CPU and database connections and '
                    . 'usually make the reindex slower. Keep one core free for the database and web traffic.',
                'fix' => 'export MAGE_INDEXER_THREADS_COUNT=7',
                'metrics' => ['threads' => 16, 'cpu_cores' => 8, 'pcntl' => true],
            ],
            $results[0]->toArray()
        );
    }

    #[DataProvider('missingPcntlProvider')]
    public function testMissingPcntlMakesParallelReindexImpossible(
        ?string $threads,
        string $severity,
        string $message
    ): void {
        $results = $this->check($threads, false, 8, 100000)->check(new DoctorContext());

        $this->assertSame(
            [
                'severity' => $severity,
                'code' => 'PARALLEL_THREADS',
                'message' => $message,
                'recommendation' => 'Parallel reindex forks processes with pcntl, which is not available in this '
                    . 'PHP CLI. Enable the extension for the CLI used by cron and bin/magento.',
                'fix' => 'Install/enable the pcntl extension for PHP CLI (php -m | grep pcntl)',
            ],
            array_slice($results[0]->toArray(), 0, 5, true)
        );
    }

    /**
     * @return array<string,array{string|null,string,string}>
     */
    public static function missingPcntlProvider(): array
    {
        return [
            'threads set' => ['4', 'warn', 'MAGE_INDEXER_THREADS_COUNT=4 is ignored: pcntl is not available'],
            'threads not set' => [null, 'info', 'Parallel reindex is not possible: pcntl is not available'],
        ];
    }

    public function testUnknownCoreCountIsReportedAsUnknown(): void
    {
        $result = $this->check(null, true, null, 100000)->check(new DoctorContext())[0]->toArray();

        $this->assertSame(
            'MAGE_INDEXER_THREADS_COUNT is not set: full reindex runs in a single process '
            . '(unknown CPU cores available, pcntl enabled)',
            $result['message']
        );
        $this->assertSame('export MAGE_INDEXER_THREADS_COUNT=2', $result['fix']);
    }

    public function testRunsOnlyFromCliBecauseItReadsTheProcessEnvironment(): void
    {
        $this->assertTrue($this->check(null, true, 8, 100)->isHeavy());
    }

    public function testThreadsNotSetOnSmallCatalogIsOnlyInfo(): void
    {
        $results = $this->check(null, true, 8, 4999)->check(new DoctorContext());

        $this->assertSame('info', $results[0]->toArray()['severity']);
    }

    /**
     * @param string|null $threads
     * @param bool $pcntl
     * @param int|null $cores
     * @param int $products
     * @return ParallelThreadsCheck
     */
    private function check(?string $threads, bool $pcntl, ?int $cores, int $products): ParallelThreadsCheck
    {
        $system = $this->createStub(SystemInfo::class);
        $system->method('isPcntlAvailable')->willReturn($pcntl);
        $system->method('getCpuCores')->willReturn($cores);
        $sizeProvider = $this->createStub(CatalogSizeProvider::class);
        $sizeProvider->method('get')->willReturn(new CatalogSize(['simple' => $products], 0, 1, 1, 4, 0, 0));

        return new ParallelThreadsCheck($system, $sizeProvider, 5000, $threads);
    }
}
