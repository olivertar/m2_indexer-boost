<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Doctor\Check;

use Magento\Framework\Indexer\ConfigInterface;
use Orangecat\IndexerBoost\Model\Doctor\Check\IndexerStateCheck;
use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use Orangecat\IndexerBoost\Test\Unit\Fake\WriteGuardAdapterTrait;
use PHPUnit\Framework\TestCase;

class IndexerStateCheckTest extends TestCase
{
    use WriteGuardAdapterTrait;

    public function testAllValidIsOk(): void
    {
        $check = $this->check([
            ['indexer_id' => 'catalog_product_price', 'status' => 'valid', 'minutes' => '5'],
            ['indexer_id' => 'catalogrule_rule', 'status' => 'valid', 'minutes' => '900'],
        ]);

        $results = $check->check(new DoctorContext());

        $this->assertSame('INDEXER_STATE', $check->getCode());
        $this->assertCount(1, $results);
        $this->assertSame(['ok', 'All indexers are valid'], [
            $results[0]->toArray()['severity'],
            $results[0]->toArray()['message'],
        ]);
        $this->assertNoWriteAttempts();
    }

    public function testInvalidIndexersAreGroupedInOneWarnWithReindexFix(): void
    {
        $check = $this->check([
            ['indexer_id' => 'catalog_product_price', 'status' => 'invalid', 'minutes' => '30'],
            ['indexer_id' => 'catalogrule_rule', 'status' => 'valid', 'minutes' => '30'],
            ['indexer_id' => 'inventory', 'status' => 'invalid', 'minutes' => '30'],
        ]);

        $results = $check->check(new DoctorContext());

        $this->assertCount(1, $results);
        $this->assertSame(
            [
                'severity' => 'warn',
                'code' => 'INDEXER_STATE',
                'message' => '2 indexer(s) invalid: catalog_product_price, inventory',
                'recommendation' => 'Invalid indexers serve stale data until rebuilt. The indexer_reindex_all_invalid '
                    . 'cron job rebuilds them; if they stay invalid, check that cron runs and look for errors in '
                    . 'var/log/exception.log.',
                'fix' => 'bin/magento indexer:reindex catalog_product_price inventory',
                'metrics' => ['indexers' => ['catalog_product_price', 'inventory']],
            ],
            $results[0]->toArray()
        );
    }

    public function testSuspendedIndexersAreWarnWithSetStatusFix(): void
    {
        $check = $this->check([
            ['indexer_id' => 'catalogrule_rule', 'status' => 'suspended', 'minutes' => '3000'],
        ]);

        $results = $check->check(new DoctorContext());

        $this->assertCount(1, $results);
        $this->assertSame(
            [
                'severity' => 'warn',
                'code' => 'INDEXER_STATE',
                'message' => '1 indexer(s) suspended: catalogrule_rule',
                'recommendation' => 'Cron skips suspended indexers while their changelog keeps growing. This is '
                    . 'usually left behind by an interrupted deployment or a manual indexer:set-status. Mark them '
                    . 'invalid so cron rebuilds them.',
                'fix' => 'bin/magento indexer:set-status invalid catalogrule_rule',
                'metrics' => ['indexers' => ['catalogrule_rule']],
            ],
            $results[0]->toArray()
        );
    }

    public function testLongWorkingIndexersAreWarnShortOnesAreIgnored(): void
    {
        $check = $this->check([
            ['indexer_id' => 'catalog_product_price', 'status' => 'working', 'minutes' => '121'],
            ['indexer_id' => 'inventory', 'status' => 'working', 'minutes' => '120'],
        ]);

        $results = $check->check(new DoctorContext());

        $this->assertCount(1, $results);
        $this->assertSame(
            [
                'severity' => 'warn',
                'code' => 'INDEXER_STATE',
                'message' => '1 indexer(s) working for more than 120 minutes: catalog_product_price (121 min)',
                'recommendation' => 'A reindex running this long may be stuck or blocked by locks. Check whether '
                    . 'the process is still alive and whether the INDEXER_STUCK check flags it.',
                'fix' => 'bin/magento indexer-boost:doctor --only=INDEXER_STUCK',
                'metrics' => ['indexers' => ['catalog_product_price' => 121]],
            ],
            $results[0]->toArray()
        );
    }

    public function testRowsOfIndexersNoLongerConfiguredAreIgnored(): void
    {
        $check = $this->check([
            ['indexer_id' => 'removed_module_indexer', 'status' => 'invalid', 'minutes' => '99999'],
        ]);

        $results = $check->check(new DoctorContext());

        $this->assertSame('ok', $results[0]->toArray()['severity']);
    }

    /**
     * @param array<int,array<string,string>> $rows indexer_state rows with minutes since update
     * @return IndexerStateCheck
     */
    private function check(array $rows): IndexerStateCheck
    {
        $config = $this->createStub(ConfigInterface::class);
        $config->method('getIndexers')->willReturn([
            'catalog_product_price' => ['indexer_id' => 'catalog_product_price'],
            'catalogrule_rule' => ['indexer_id' => 'catalogrule_rule'],
            'inventory' => ['indexer_id' => 'inventory'],
        ]);
        $adapter = $this->createWriteGuardAdapter(static fn (): array => $rows);

        return new IndexerStateCheck($config, new ReadOnlyConnection($this->createResourceConnection($adapter)), 120);
    }
}
