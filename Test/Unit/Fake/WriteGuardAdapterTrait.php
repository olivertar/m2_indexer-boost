<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Fake;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\MockObject\Stub;

/**
 * Fake DB adapter that records and rejects any write attempt.
 *
 * Violations are recorded before throwing, so a test still fails through
 * assertNoWriteAttempts() even when the code under test swallows the exception.
 */
trait WriteGuardAdapterTrait
{
    /**
     * Adapter methods that modify data or schema
     *
     * @var string[]
     */
    private static array $writeMethods = [
        'createTable', 'dropTable', 'createTemporaryTable', 'createTemporaryTableLike', 'dropTemporaryTable',
        'renameTablesBatch', 'truncateTable', 'createTableByDdl', 'modifyColumnByDdl', 'renameTable',
        'addColumn', 'changeColumn', 'modifyColumn', 'dropColumn', 'addIndex', 'dropIndex',
        'addForeignKey', 'dropForeignKey', 'insertOnDuplicate', 'insertMultiple', 'insertArray',
        'insert', 'insertForce', 'update', 'delete', 'disableTableKeys', 'enableTableKeys',
        'insertFromSelect', 'updateFromSelect', 'deleteFromSelect', 'createTrigger', 'dropTrigger',
        'startSetup', 'endSetup', 'forUpdate',
    ];

    /**
     * @var string[]
     */
    private array $writeGuardViolations = [];

    /**
     * SQL statements received by the fake adapter, in order
     *
     * @var string[]
     */
    private array $writeGuardQueries = [];

    /**
     * Create a fake adapter.
     *
     * $responder receives the SQL, the fetch method name and the bind array and returns the fetch result.
     *
     * @param callable(string, string, array<mixed>): mixed $responder
     * @return AdapterInterface&Stub
     */
    private function createWriteGuardAdapter(callable $responder): AdapterInterface
    {
        $adapter = $this->createStub(AdapterInterface::class);

        foreach (self::$writeMethods as $method) {
            $adapter->method($method)->willReturnCallback(
                function () use ($method): never {
                    $this->recordWriteAttempt('method ' . $method . '()');
                }
            );
        }

        foreach (['query', 'fetchAll', 'fetchRow', 'fetchAssoc', 'fetchCol', 'fetchPairs', 'fetchOne'] as $method) {
            $adapter->method($method)->willReturnCallback(
                function ($sql, $bind = []) use ($method, $responder) {
                    $sql = (string)$sql;
                    $this->writeGuardQueries[] = $sql;
                    $code = (string)preg_replace(["~'(?:[^'\\\\]|\\\\.|'')*'~s", '~`[^`]*`~'], '?', $sql);
                    if (preg_match(
                        '/\b(INSERT|UPDATE|DELETE|ALTER|TRUNCATE|REPLACE|CREATE|DROP|RENAME)\b/i',
                        $code
                    )) {
                        $this->recordWriteAttempt($sql);
                    }
                    return $responder($sql, $method, (array)$bind);
                }
            );
        }

        return $adapter;
    }

    /**
     * Create a ResourceConnection whose default connection is the given adapter
     *
     * @param AdapterInterface $adapter
     * @return ResourceConnection&Stub
     */
    private function createResourceConnection(AdapterInterface $adapter): ResourceConnection
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($adapter);
        $resource->method('getTableName')->willReturnCallback(
            static fn ($name): string => 'pfx_' . (is_array($name) ? implode('_', $name) : $name)
        );
        return $resource;
    }

    /**
     * Fail the test if any write reached the adapter
     *
     * @return void
     */
    private function assertNoWriteAttempts(): void
    {
        $this->assertSame([], $this->writeGuardViolations, 'Write attempts reached the DB adapter');
    }

    /**
     * Record and reject a write attempt
     *
     * @param string $what
     * @return never
     */
    private function recordWriteAttempt(string $what): never
    {
        $this->writeGuardViolations[] = $what;
        throw new \LogicException('Write attempt rejected by fake adapter: ' . $what);
    }
}
