<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Doctor\Check;

use Orangecat\IndexerBoost\Model\Doctor\CheckInterface;
use Orangecat\IndexerBoost\Model\Doctor\CheckResult;
use Orangecat\IndexerBoost\Model\Doctor\Db\DbServer;
use Orangecat\IndexerBoost\Model\Doctor\Db\DbServerProvider;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use Orangecat\IndexerBoost\Model\Doctor\Severity;

/**
 * InnoDB and temporary table variables that weigh most on indexing
 */
class MysqlVarsCheck implements CheckInterface
{
    public const CODE = 'MYSQL_VARS';

    private const MB = 1048576;
    private const GB = 1073741824;
    private const DEFAULT_BUFFER_POOL = 134217728;
    private const CRITICAL_DATABASE_SIZE = 256 * self::MB;
    private const MIN_TMP_TABLE = 64 * self::MB;
    private const MIN_REDO_LOG = self::GB;

    /**
     * Variables reported as metrics
     */
    public const VARIABLES = [
        'innodb_buffer_pool_size',
        'tmp_table_size',
        'max_heap_table_size',
        'temptable_max_ram',
        'innodb_redo_log_capacity',
        'innodb_log_file_size',
        'innodb_lock_wait_timeout',
        'innodb_flush_log_at_trx_commit',
    ];

    /**
     * @param DbServerProvider $dbServerProvider
     */
    public function __construct(
        private readonly DbServerProvider $dbServerProvider
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getCode(): string
    {
        return self::CODE;
    }

    /**
     * @inheritdoc
     */
    public function isHeavy(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function check(DoctorContext $context): array
    {
        $server = $this->dbServerProvider->get($context);
        $results = array_filter([
            $this->checkBufferPool($server),
            $this->checkTemporaryTables($server),
            $this->checkRedoLog($server),
            $this->checkFlushLog($server),
        ]);

        if ($results) {
            return array_values($results);
        }

        $metrics = [];
        foreach (self::VARIABLES as $name) {
            $metrics[$name] = $server->getVariable($name);
        }
        return [
            new CheckResult(
                Severity::Ok,
                self::CODE,
                sprintf('%s %s variables look reasonable for indexing', $server->getEngine(), $server->getSeries()),
                '',
                '',
                $metrics
            ),
        ];
    }

    /**
     * Buffer pool against data + index size
     *
     * @param DbServer $server
     * @return CheckResult|null
     */
    private function checkBufferPool(DbServer $server): ?CheckResult
    {
        $bufferPool = (int)$server->getVariable('innodb_buffer_pool_size');
        $databaseSize = $server->getDatabaseSize();
        if ($bufferPool >= $databaseSize) {
            return null;
        }

        $isDefault = $bufferPool === self::DEFAULT_BUFFER_POOL && $databaseSize > self::CRITICAL_DATABASE_SIZE;
        return new CheckResult(
            $isDefault ? Severity::Critical : Severity::Warn,
            self::CODE,
            $isDefault
                ? sprintf(
                    'innodb_buffer_pool_size is still the 128 MB default with %d MB of data and indexes',
                    $this->toMb($databaseSize)
                )
                : sprintf(
                    'innodb_buffer_pool_size (%d MB) is smaller than data and indexes (%d MB)',
                    $this->toMb($bufferPool),
                    $this->toMb($databaseSize)
                ),
            'Indexers read and rewrite large tables; when they do not fit in the buffer pool every batch goes to '
            . 'disk. Size it to hold data and indexes (leave room for the OS and PHP on shared hosts).',
            sprintf(
                'my.cnf [mysqld]: innodb_buffer_pool_size = %dG',
                max(1, (int)ceil($databaseSize * 1.2 / self::GB))
            ),
            ['innodb_buffer_pool_size' => $bufferPool, 'database_size' => $databaseSize]
        );
    }

    /**
     * Effective in-memory temporary table limit: the lower of tmp_table_size and max_heap_table_size
     *
     * @param DbServer $server
     * @return CheckResult|null
     */
    private function checkTemporaryTables(DbServer $server): ?CheckResult
    {
        $tmpTableSize = (int)$server->getVariable('tmp_table_size');
        $maxHeapTableSize = (int)$server->getVariable('max_heap_table_size');
        $effective = min($tmpTableSize, $maxHeapTableSize);
        $metrics = ['tmp_table_size' => $tmpTableSize, 'max_heap_table_size' => $maxHeapTableSize];

        if ($effective < self::MIN_TMP_TABLE) {
            return new CheckResult(
                Severity::Warn,
                self::CODE,
                sprintf(
                    'In-memory temporary tables are limited to %d MB (lower of tmp_table_size=%d MB and '
                    . 'max_heap_table_size=%d MB)',
                    $this->toMb($effective),
                    $this->toMb($tmpTableSize),
                    $this->toMb($maxHeapTableSize)
                ),
                'Index queries with GROUP BY/DISTINCT build temporary tables; above this limit they go to disk. '
                . 'Set both variables to the same value between 64 and 256 MB.',
                'my.cnf [mysqld]: tmp_table_size = 128M, max_heap_table_size = 128M',
                $metrics
            );
        }

        if ($tmpTableSize !== $maxHeapTableSize) {
            return new CheckResult(
                Severity::Info,
                self::CODE,
                sprintf(
                    'tmp_table_size (%d MB) and max_heap_table_size (%d MB) differ: only the lower one applies',
                    $this->toMb($tmpTableSize),
                    $this->toMb($maxHeapTableSize)
                ),
                'Set both variables to the same value so the configured limit is the effective one.',
                sprintf(
                    'my.cnf [mysqld]: tmp_table_size = %1$dM, max_heap_table_size = %1$dM',
                    $this->toMb($effective)
                ),
                $metrics
            );
        }

        return null;
    }

    /**
     * Redo log capacity (innodb_redo_log_capacity on MySQL 8.0.30+, innodb_log_file_size otherwise)
     *
     * @param DbServer $server
     * @return CheckResult|null
     */
    private function checkRedoLog(DbServer $server): ?CheckResult
    {
        $capacityVariable = $server->getVariable('innodb_redo_log_capacity') !== null
            ? 'innodb_redo_log_capacity'
            : 'innodb_log_file_size';
        $capacity = (int)$server->getVariable($capacityVariable);
        if ($capacityVariable === 'innodb_log_file_size') {
            $capacity *= max(1, (int)($server->getVariable('innodb_log_files_in_group') ?? 1));
        }
        if ($capacity >= self::MIN_REDO_LOG) {
            return null;
        }

        return new CheckResult(
            Severity::Info,
            self::CODE,
            sprintf('Redo log capacity is %d MB', $this->toMb($capacity)),
            'A full reindex writes many rows quickly; a small redo log forces frequent checkpoints and stalls '
            . 'writes. 1-2 GB is a common starting point for catalogs with heavy indexing.',
            sprintf('my.cnf [mysqld]: %s = 1G', $capacityVariable),
            [$capacityVariable => (int)$server->getVariable($capacityVariable), 'redo_log_capacity' => $capacity]
        );
    }

    /**
     * Durability setting of the InnoDB log flush
     *
     * @param DbServer $server
     * @return CheckResult|null
     */
    private function checkFlushLog(DbServer $server): ?CheckResult
    {
        if ($server->getVariable('innodb_flush_log_at_trx_commit') !== '1') {
            return null;
        }

        return new CheckResult(
            Severity::Info,
            self::CODE,
            'innodb_flush_log_at_trx_commit = 1 (flush to disk on every commit)',
            'Large reindexes commit many batches. A value of 2 flushes once per second and is much faster, at the '
            . 'cost of losing up to ~1 s of transactions if the OS crashes. Decide per environment.',
            'my.cnf [mysqld]: innodb_flush_log_at_trx_commit = 2',
            ['innodb_flush_log_at_trx_commit' => 1]
        );
    }

    /**
     * Bytes to rounded megabytes
     *
     * @param int $bytes
     * @return int
     */
    private function toMb(int $bytes): int
    {
        return (int)round($bytes / self::MB);
    }
}
