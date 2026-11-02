<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Repair;

use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;
use Orangecat\IndexerBoost\Model\Repair\ProcessListReader;
use Orangecat\IndexerBoost\Test\Unit\Fake\WriteGuardAdapterTrait;
use PHPUnit\Framework\TestCase;

class ProcessListReaderTest extends TestCase
{
    use WriteGuardAdapterTrait;

    public function testProcessPrivilegeOfTheCurrentUser(): void
    {
        $queries = [];
        $reader = $this->reader(static function (string $sql) use (&$queries): string {
            $queries[] = $sql;
            return '1';
        });

        $this->assertTrue($reader->hasProcessPrivilege());
        $this->assertStringContainsString('information_schema.USER_PRIVILEGES', $queries[0]);
        $this->assertStringContainsString("PRIVILEGE_TYPE = 'PROCESS'", $queries[0]);
        $this->assertStringContainsString('CURRENT_USER()', $queries[0]);
        $this->assertNoWriteAttempts();
    }

    public function testWithoutPrivilegeRowThereIsNoProcessPrivilege(): void
    {
        $this->assertFalse($this->reader(static fn (): string => '0')->hasProcessPrivilege());
    }

    public function testWriteStatementsOfOtherConnections(): void
    {
        $queries = [];
        $reader = $this->reader(static function (string $sql) use (&$queries): array {
            $queries[] = $sql;
            return [
                'SELECT * FROM pfx_catalog_product_index_price WHERE entity_id IN (1, 2)',
                'INSERT INTO pfx_catalog_product_index_price_temp SELECT * FROM pfx_catalog_product_entity',
                "/* indexer */ \n  delete from pfx_catalogrule_product WHERE rule_id = 4",
                'SHOW TABLE STATUS',
                'TRUNCATE TABLE pfx_catalog_category_product_index_replica',
                'WITH ids AS (SELECT 1) SELECT * FROM ids',
            ];
        });

        $this->assertSame(
            [
                'INSERT INTO pfx_catalog_product_index_price_temp SELECT * FROM pfx_catalog_product_entity',
                "/* indexer */ \n  delete from pfx_catalogrule_product WHERE rule_id = 4",
                'TRUNCATE TABLE pfx_catalog_category_product_index_replica',
            ],
            $reader->getWriteStatements()
        );
        $this->assertStringContainsString('information_schema.PROCESSLIST', $queries[0]);
        $this->assertStringContainsString('ID <> CONNECTION_ID()', $queries[0]);
        $this->assertStringContainsString('INFO IS NOT NULL', $queries[0]);
    }

    /**
     * @param callable(string, string, array<mixed>): mixed $responder
     * @return ProcessListReader
     */
    private function reader(callable $responder): ProcessListReader
    {
        return new ProcessListReader(
            new ReadOnlyConnection($this->createResourceConnection($this->createWriteGuardAdapter($responder)))
        );
    }
}
