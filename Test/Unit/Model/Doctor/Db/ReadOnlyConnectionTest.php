<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Doctor\Db;

use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;
use Orangecat\IndexerBoost\Model\Doctor\Db\WriteNotAllowedException;
use Orangecat\IndexerBoost\Test\Unit\Fake\WriteGuardAdapterTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReadOnlyConnectionTest extends TestCase
{
    use WriteGuardAdapterTrait;

    public function testFetchAllPassesSelectToAdapter(): void
    {
        $adapter = $this->createWriteGuardAdapter(
            static fn (string $sql, string $method, array $bind): array => [['entity_id' => $bind[0]]]
        );
        $connection = new ReadOnlyConnection($this->createResourceConnection($adapter));

        $rows = $connection->fetchAll('SELECT entity_id FROM t WHERE entity_id = ?', [7]);

        $this->assertSame([['entity_id' => 7]], $rows);
        $this->assertNoWriteAttempts();
    }

    #[DataProvider('writeStatementProvider')]
    public function testWriteStatementIsRejectedBeforeReachingAdapter(string $sql): void
    {
        $adapter = $this->createWriteGuardAdapter(static fn (): array => []);
        $connection = new ReadOnlyConnection($this->createResourceConnection($adapter));

        try {
            $connection->fetchAll($sql);
            $this->fail('Write statement was not rejected: ' . $sql);
        } catch (WriteNotAllowedException) {
            $this->assertSame([], $this->writeGuardQueries);
        }
    }

    #[DataProvider('commentedSelectProvider')]
    public function testLeadingCommentsAndParenthesesAreSkipped(string $sql): void
    {
        $adapter = $this->createWriteGuardAdapter(static fn (): array => [['one' => 1]]);
        $connection = new ReadOnlyConnection($this->createResourceConnection($adapter));

        $this->assertSame([['one' => 1]], $connection->fetchAll($sql));
    }

    /**
     * @return array<string,array{string}>
     */
    public static function commentedSelectProvider(): array
    {
        return [
            'block comment' => ['/* hint */ SELECT 1 AS one'],
            'dash comment' => ["-- note\nSELECT 1 AS one"],
            'hash comment' => ["# note\n  SELECT 1 AS one"],
            'parentheses' => ['((SELECT 1 AS one))'],
            'mixed' => ["/* a */\n-- b\n(SELECT 1 AS one)"],
        ];
    }

    public function testGetTableNameAppliesConfiguredPrefix(): void
    {
        $adapter = $this->createWriteGuardAdapter(static fn (): array => []);
        $connection = new ReadOnlyConnection($this->createResourceConnection($adapter));

        $this->assertSame('pfx_indexer_state', $connection->getTableName('indexer_state'));
    }

    #[DataProvider('fetchMethodProvider')]
    public function testEveryFetchMethodDelegatesAndIsGuarded(string $method, mixed $result): void
    {
        $adapter = $this->createWriteGuardAdapter(
            static fn (string $sql, string $called): mixed => $called === $method ? $result : 'wrong method'
        );
        $connection = new ReadOnlyConnection($this->createResourceConnection($adapter));

        $this->assertSame($result, $connection->{$method}('SELECT a FROM t WHERE b = ?', [1]));

        $this->expectException(WriteNotAllowedException::class);
        $connection->{$method}('DELETE FROM t');
    }

    /**
     * @return array<string,array{string,mixed}>
     */
    public static function fetchMethodProvider(): array
    {
        return [
            'fetchRow' => ['fetchRow', ['a' => 1]],
            'fetchCol' => ['fetchCol', [1, 2]],
            'fetchPairs' => ['fetchPairs', [1 => 'x']],
            'fetchOne' => ['fetchOne', '42'],
        ];
    }

    #[DataProvider('safeReadProvider')]
    public function testKeywordsInLiteralsOrIdentifiersAreNotFalsePositives(string $sql): void
    {
        $adapter = $this->createWriteGuardAdapter(static fn (): array => []);
        $connection = new ReadOnlyConnection($this->createResourceConnection($adapter));

        $this->assertSame([], $connection->fetchAll($sql));
    }

    /**
     * @return array<string,array{string}>
     */
    public static function safeReadProvider(): array
    {
        return [
            'string literal' => ["SELECT * FROM cron_schedule WHERE messages LIKE '%; select into x for update%'"],
            'escaped quote' => ["SELECT 'it''s; into' AS a"],
            'quoted identifier' => ['SELECT `into` FROM t'],
            'similar column names' => ['SELECT updated, update_time, is_deleted FROM indexer_state'],
            'cte with literal' => ["WITH x AS (SELECT 'delete' AS a) SELECT a FROM x"],
            'trailing semicolon' => ['SELECT 1;  '],
            'show variables' => ["SHOW GLOBAL VARIABLES LIKE 'innodb%'"],
        ];
    }

    #[DataProvider('unsafeReadProvider')]
    public function testUnsafeConstructInsideReadStatementIsRejected(string $sql): void
    {
        $adapter = $this->createWriteGuardAdapter(static fn (): array => []);
        $connection = new ReadOnlyConnection($this->createResourceConnection($adapter));

        try {
            $connection->fetchAll($sql);
            $this->fail('Unsafe statement was not rejected: ' . $sql);
        } catch (WriteNotAllowedException) {
            $this->assertSame([], $this->writeGuardQueries);
        }
    }

    /**
     * @return array<string,array{string}>
     */
    public static function unsafeReadProvider(): array
    {
        return [
            'executable comment' => ['SELECT 1 /*!50000 , SLEEP(1) */'],
            'stacked statements' => ['SELECT 1; DELETE FROM t'],
            'for update' => ['SELECT * FROM t WHERE a = 1 FOR UPDATE'],
            'for share' => ['SELECT * FROM t FOR SHARE'],
            'lock in share mode' => ['SELECT * FROM t LOCK IN SHARE MODE'],
            'into outfile' => ["SELECT * FROM t INTO OUTFILE '/tmp/x'"],
            'into dumpfile' => ["SELECT a FROM t INTO DUMPFILE '/tmp/x'"],
            'into variable' => ['SELECT a INTO @v FROM t'],
            'cte delete' => ['WITH x AS (SELECT 1 AS a) DELETE FROM t WHERE a IN (SELECT a FROM x)'],
            'cte update' => ['WITH x AS (SELECT 1) UPDATE t SET a = 1'],
            'get lock' => ["SELECT GET_LOCK('x', 1)"],
            'release lock' => ["SELECT RELEASE_LOCK('x')"],
        ];
    }

    /**
     * @return array<string,array{string}>
     */
    public static function writeStatementProvider(): array
    {
        return [
            'insert' => ['INSERT INTO t (a) VALUES (1)'],
            'update' => ['UPDATE t SET a = 1'],
            'delete' => ['DELETE FROM t'],
            'alter' => ['ALTER TABLE t ADD COLUMN b INT'],
            'truncate' => ['TRUNCATE TABLE t'],
            'replace' => ['REPLACE INTO t (a) VALUES (1)'],
            'create' => ['CREATE TABLE t2 (a INT)'],
            'drop' => ['DROP TABLE t'],
            'rename' => ['RENAME TABLE t TO t2'],
            'analyze' => ['ANALYZE TABLE t'],
            'set' => ['SET GLOBAL innodb_buffer_pool_size = 1'],
            'lowercase' => ['  delete from t'],
        ];
    }
}
