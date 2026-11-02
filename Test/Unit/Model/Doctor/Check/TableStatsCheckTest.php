<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Doctor\Check;

use Orangecat\IndexerBoost\Model\Doctor\Check\TableStatsCheck;
use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use Orangecat\IndexerBoost\Test\Unit\Fake\WriteGuardAdapterTrait;
use PHPUnit\Framework\TestCase;

class TableStatsCheckTest extends TestCase
{
    use WriteGuardAdapterTrait;

    private const TABLES = [
        ['TABLE_NAME' => 'pfx_catalog_product_index_price', 'TABLE_ROWS' => '9000000', 'size' => '524288000'],
        ['TABLE_NAME' => 'pfx_catalog_product_entity_int', 'TABLE_ROWS' => '4000000', 'size' => '209715200'],
        ['TABLE_NAME' => 'pfx_catalogrule_product', 'TABLE_ROWS' => '10000', 'size' => '10485760'],
    ];

    public function testRecentStatisticsAreOk(): void
    {
        $check = $this->check(['pfx_catalog_product_index_price' => '2', 'pfx_catalog_product_entity_int' => '10']);

        $result = $check->check(new DoctorContext())[0]->toArray();

        $this->assertSame('TABLE_STATS', $result['code']);
        $this->assertSame(['ok', 'Statistics of the 2 large index and EAV tables are recent'], [
            $result['severity'],
            $result['message'],
        ]);
        $this->assertSame(
            ['size_mb' => 500, 'rows' => 9000000, 'stats_age_days' => 2],
            $result['metrics']['tables']['pfx_catalog_product_index_price']
        );
        $this->assertStringContainsString('LIMIT 10', $this->writeGuardQueries[0]);
        $this->assertNoWriteAttempts();
    }

    public function testStaleStatisticsOnLargeTablesAreWarn(): void
    {
        $check = $this->check(['pfx_catalog_product_index_price' => '45', 'pfx_catalogrule_product' => '90']);

        $result = $check->check(new DoctorContext())[0]->toArray();

        $this->assertSame('warn', $result['severity']);
        $this->assertSame(
            'Stale or missing statistics on large tables: pfx_catalog_product_index_price (500 MB, 45 days), '
            . 'pfx_catalog_product_entity_int (200 MB, never)',
            $result['message']
        );
        $this->assertSame(
            'After large imports or catalog changes the optimizer may use outdated row estimates and pick slow '
            . 'plans for index queries. Run ANALYZE TABLE in a quiet period (the doctor never runs it).',
            $result['recommendation']
        );
        $this->assertSame(
            'ANALYZE TABLE pfx_catalog_product_index_price, pfx_catalog_product_entity_int;',
            $result['fix']
        );
    }

    public function testMissingPrivilegeOnStatisticsReportsSizesAsInfo(): void
    {
        $check = $this->check(null);

        $result = $check->check(new DoctorContext())[0]->toArray();

        $this->assertSame('info', $result['severity']);
        $this->assertSame(
            'Large index and EAV tables: pfx_catalog_product_index_price (500 MB), pfx_catalog_product_entity_int '
            . '(200 MB). Statistics dates are not available (no SELECT privilege on mysql.innodb_table_stats)',
            $result['message']
        );
        $this->assertSame(
            'ANALYZE TABLE pfx_catalog_product_index_price, pfx_catalog_product_entity_int;',
            $result['fix']
        );
        $this->assertNoWriteAttempts();
    }

    public function testOnlySmallTablesIsOkAndChangelogsAreExcluded(): void
    {
        $adapter = $this->createWriteGuardAdapter(static fn (): array => [
            ['TABLE_NAME' => 'pfx_catalog_product_index_price', 'TABLE_ROWS' => '9000', 'size' => '3145728'],
        ]);
        $check = new TableStatsCheck(
            new ReadOnlyConnection($this->createResourceConnection($adapter)),
            ['catalog_product_index%'],
            10,
            100
        );

        $result = $check->check(new DoctorContext())[0]->toArray();

        $this->assertSame(
            ['ok', 'No index or EAV table reaches 100 MB (largest: pfx_catalog_product_index_price, 3 MB)'],
            [$result['severity'], $result['message']]
        );
        $this->assertStringContainsString("TABLE_NAME NOT LIKE '%\\_cl'", $this->writeGuardQueries[0]);
        $this->assertCount(1, $this->writeGuardQueries);
    }

    public function testPatternsConfiguredWithNamedKeysInDiXmlAreBoundPositionally(): void
    {
        $binds = [];
        $recorder = static function (string $sql, string $method, array $bind) use (&$binds): array {
            $binds[] = $bind;
            return str_contains($sql, 'innodb_table_stats') ? [] : self::TABLES;
        };
        $adapter = $this->createWriteGuardAdapter($recorder);
        $check = new TableStatsCheck(
            new ReadOnlyConnection($this->createResourceConnection($adapter)),
            ['product_eav' => 'catalog_product_entity%', 'catalogrule' => 'catalogrule%']
        );

        $check->check(new DoctorContext());

        $this->assertSame(['pfx_catalog_product_entity%', 'pfx_catalogrule%'], $binds[0]);
        $this->assertTrue(array_is_list($binds[1]));
    }

    /**
     * @param array<string,string>|null $statsAgeDays table => days since last statistics update, null = denied
     * @return TableStatsCheck
     */
    private function check(?array $statsAgeDays): TableStatsCheck
    {
        $adapter = $this->createWriteGuardAdapter(static function (string $sql) use ($statsAgeDays): array {
            if (str_contains($sql, 'innodb_table_stats')) {
                if ($statsAgeDays === null) {
                    throw new \RuntimeException('SELECT command denied for table innodb_table_stats');
                }
                return $statsAgeDays;
            }
            return self::TABLES;
        });

        return new TableStatsCheck(
            new ReadOnlyConnection($this->createResourceConnection($adapter)),
            ['catalog_product_entity%', 'catalog_product_index%', 'catalogrule%'],
            10,
            100,
            30
        );
    }
}
