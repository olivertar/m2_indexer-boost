<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Doctor\Check;

use Orangecat\IndexerBoost\Model\Doctor\Check\MviewBacklogCheck;
use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use Orangecat\IndexerBoost\Test\Unit\Fake\WriteGuardAdapterTrait;
use PHPUnit\Framework\TestCase;

class MviewBacklogCheckTest extends TestCase
{
    use WriteGuardAdapterTrait;

    public function testSmallBacklogIsOk(): void
    {
        $check = $this->check(
            ['catalog_product_price' => '23000'],
            ['pfx_catalog_product_price_cl' => ['min_id' => '1', 'max_id' => '23868']]
        );

        $results = $check->check(new DoctorContext());

        $this->assertSame('MVIEW_BACKLOG', $check->getCode());
        $this->assertCount(1, $results);
        $this->assertSame(
            ['ok', 'No changelog backlog (1 view checked, 868 entries pending in total)'],
            [$results[0]->toArray()['severity'], $results[0]->toArray()['message']]
        );
        $this->assertNoWriteAttempts();
    }

    public function testBacklogAboveThresholdIsWarn(): void
    {
        $check = $this->check(
            ['catalog_product_price' => '100', 'catalogrule_rule' => '50', 'inventory' => '0'],
            [
                'pfx_catalog_product_price_cl' => ['min_id' => '1', 'max_id' => '10100'],
                'pfx_catalogrule_rule_cl' => ['min_id' => '1', 'max_id' => '60'],
                'pfx_inventory_cl' => ['min_id' => null, 'max_id' => null],
            ]
        );

        $result = $check->check(new DoctorContext())[0]->toArray();

        $this->assertSame(
            [
                'severity' => 'warn',
                'code' => 'MVIEW_BACKLOG',
                'message' => 'Changelog backlog: catalog_product_price (10000 pending)',
                'recommendation' => 'These entries wait for the indexer_update_all_views cron job. A growing backlog '
                    . 'means the index cron group is not running or cannot keep up, and the storefront shows stale '
                    . 'data meanwhile.',
                'fix' => 'bin/magento cron:run --group=index (then check CRON_INDEXER)',
                'metrics' => ['pending' => ['catalog_product_price' => 10000]],
            ],
            $result
        );
    }

    public function testLargeChangelogIsInfoAndMissingTablesAreSkipped(): void
    {
        $check = $this->check(
            ['catalog_product_price' => '2000005', 'customer_dummy' => '4'],
            ['pfx_catalog_product_price_cl' => ['min_id' => '6', 'max_id' => '2000005']]
        );

        $results = $check->check(new DoctorContext());

        $this->assertCount(1, $results);
        $this->assertSame(
            [
                'severity' => 'info',
                'code' => 'MVIEW_BACKLOG',
                'message' => 'Large changelog tables: catalog_product_price (~2000000 rows)',
                'recommendation' => 'Processed entries are removed by the indexer_clean_all_changelogs cron job. '
                    . 'A very large changelog slows down every mview run and wastes disk.',
                'fix' => 'Check that indexer_clean_all_changelogs runs: bin/magento cron:run --group=index',
                'metrics' => ['rows' => ['catalog_product_price' => 2000000]],
            ],
            $results[0]->toArray()
        );
    }

    /**
     * @param array<string,string> $versions view_id => mview_state.version_id
     * @param array<string,array<string,string|null>> $changelogs table => min/max version
     * @return MviewBacklogCheck
     */
    private function check(array $versions, array $changelogs): MviewBacklogCheck
    {
        $adapter = $this->createWriteGuardAdapter(static function (string $sql) use ($versions, $changelogs): mixed {
            if (str_contains($sql, 'pfx_mview_state')) {
                return $versions;
            }
            if (str_contains($sql, 'information_schema.TABLES')) {
                return array_keys($changelogs);
            }
            foreach ($changelogs as $table => $row) {
                if (str_contains($sql, 'FROM ' . $table)) {
                    return $row;
                }
            }
            throw new \LogicException('Unexpected query: ' . $sql);
        });

        return new MviewBacklogCheck(new ReadOnlyConnection($this->createResourceConnection($adapter)), 10000, 1000000);
    }
}
