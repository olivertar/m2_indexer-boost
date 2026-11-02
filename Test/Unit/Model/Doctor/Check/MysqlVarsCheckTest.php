<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Doctor\Check;

use Orangecat\IndexerBoost\Model\Doctor\Check\MysqlVarsCheck;
use Orangecat\IndexerBoost\Model\Doctor\Db\DbServer;
use Orangecat\IndexerBoost\Model\Doctor\Db\DbServerProvider;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use PHPUnit\Framework\TestCase;

class MysqlVarsCheckTest extends TestCase
{
    private const MB = 1048576;

    private const GOOD = [
        'innodb_buffer_pool_size' => '4294967296',
        'tmp_table_size' => '134217728',
        'max_heap_table_size' => '134217728',
        'innodb_log_file_size' => '2147483648',
        'innodb_flush_log_at_trx_commit' => '2',
        'innodb_lock_wait_timeout' => '50',
    ];

    public function testWellTunedServerIsOkWithVariablesAsMetrics(): void
    {
        $results = $this->findings(self::GOOD, 1024 * self::MB);

        $this->assertCount(1, $results);
        $this->assertSame('ok', $results[0]['severity']);
        $this->assertSame('MYSQL_VARS', $results[0]['code']);
        $this->assertSame('50', $results[0]['metrics']['innodb_lock_wait_timeout']);
        $this->assertNull($results[0]['metrics']['temptable_max_ram']);
    }

    public function testDefaultBufferPoolWithLargeDatabaseIsCritical(): void
    {
        $results = $this->findings(['innodb_buffer_pool_size' => '134217728'] + self::GOOD, 300 * self::MB);

        $this->assertSame(
            [
                'severity' => 'critical',
                'code' => 'MYSQL_VARS',
                'message' => 'innodb_buffer_pool_size is still the 128 MB default with 300 MB of data and indexes',
                'recommendation' => 'Indexers read and rewrite large tables; when they do not fit in the buffer '
                    . 'pool every batch goes to disk. Size it to hold data and indexes (leave room for the OS and '
                    . 'PHP on shared hosts).',
                'fix' => 'my.cnf [mysqld]: innodb_buffer_pool_size = 1G',
                'metrics' => ['innodb_buffer_pool_size' => 134217728, 'database_size' => 314572800],
            ],
            $results[0]
        );
    }

    public function testBufferPoolSmallerThanDataIsWarn(): void
    {
        $results = $this->findings(
            ['innodb_buffer_pool_size' => (string)(1024 * self::MB)] + self::GOOD,
            3000 * self::MB
        );

        $this->assertSame('warn', $results[0]['severity']);
        $this->assertSame(
            'innodb_buffer_pool_size (1024 MB) is smaller than data and indexes (3000 MB)',
            $results[0]['message']
        );
        $this->assertSame('my.cnf [mysqld]: innodb_buffer_pool_size = 4G', $results[0]['fix']);
    }

    public function testSmallTemporaryTableLimitIsWarnUsingTheLowerValue(): void
    {
        $results = $this->findings(
            ['tmp_table_size' => (string)(256 * self::MB), 'max_heap_table_size' => (string)(16 * self::MB)]
            + self::GOOD,
            100 * self::MB
        );

        $this->assertSame(
            [
                'severity' => 'warn',
                'code' => 'MYSQL_VARS',
                'message' => 'In-memory temporary tables are limited to 16 MB '
                    . '(lower of tmp_table_size=256 MB and max_heap_table_size=16 MB)',
                'recommendation' => 'Index queries with GROUP BY/DISTINCT build temporary tables; above this limit '
                    . 'they go to disk. Set both variables to the same value between 64 and 256 MB.',
                'fix' => 'my.cnf [mysqld]: tmp_table_size = 128M, max_heap_table_size = 128M',
                'metrics' => ['tmp_table_size' => 268435456, 'max_heap_table_size' => 16777216],
            ],
            $results[0]
        );
    }

    public function testDifferentTemporaryTableValuesWithinRangeIsInfo(): void
    {
        $results = $this->findings(['tmp_table_size' => (string)(64 * self::MB)] + self::GOOD, 100 * self::MB);

        $this->assertSame('info', $results[0]['severity']);
        $this->assertSame(
            'tmp_table_size (64 MB) and max_heap_table_size (128 MB) differ: only the lower one applies',
            $results[0]['message']
        );
    }

    public function testSmallRedoLogIsInfoWithEngineSpecificFix(): void
    {
        $mariaDb = $this->findings(['innodb_log_file_size' => (string)(96 * self::MB)] + self::GOOD, 100 * self::MB);
        $mysql = $this->findings(
            ['innodb_redo_log_capacity' => (string)(100 * self::MB)] + self::GOOD,
            100 * self::MB,
            '8.4.3'
        );

        $this->assertSame('info', $mariaDb[0]['severity']);
        $this->assertSame('Redo log capacity is 96 MB', $mariaDb[0]['message']);
        $this->assertSame('my.cnf [mysqld]: innodb_log_file_size = 1G', $mariaDb[0]['fix']);
        $this->assertSame('Redo log capacity is 100 MB', $mysql[0]['message']);
        $this->assertSame('my.cnf [mysqld]: innodb_redo_log_capacity = 1G', $mysql[0]['fix']);
    }

    public function testFullDurabilityFlushIsInfo(): void
    {
        $results = $this->findings(['innodb_flush_log_at_trx_commit' => '1'] + self::GOOD, 100 * self::MB);

        $this->assertSame(
            [
                'severity' => 'info',
                'code' => 'MYSQL_VARS',
                'message' => 'innodb_flush_log_at_trx_commit = 1 (flush to disk on every commit)',
                'recommendation' => 'Large reindexes commit many batches. A value of 2 flushes once per second and '
                    . 'is much faster, at the cost of losing up to ~1 s of transactions if the OS crashes. Decide '
                    . 'per environment.',
                'fix' => 'my.cnf [mysqld]: innodb_flush_log_at_trx_commit = 2',
                'metrics' => ['innodb_flush_log_at_trx_commit' => 1],
            ],
            $results[0]
        );
    }

    /**
     * @param array<string,string> $variables
     * @param int $databaseSize
     * @param string $version
     * @return array<int,array<string,mixed>>
     */
    private function findings(array $variables, int $databaseSize, string $version = '10.11.19-MariaDB'): array
    {
        $provider = $this->createStub(DbServerProvider::class);
        $provider->method('get')->willReturn(new DbServer($version, $variables, $databaseSize));
        $check = new MysqlVarsCheck($provider);

        return array_map(static fn ($result): array => $result->toArray(), $check->check(new DoctorContext()));
    }
}
