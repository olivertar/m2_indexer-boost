<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Doctor\Check;

use Orangecat\IndexerBoost\Model\Doctor\CheckInterface;
use Orangecat\IndexerBoost\Model\Doctor\CheckResult;
use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use Orangecat\IndexerBoost\Model\Doctor\Severity;

/**
 * Size of the largest EAV and index tables and age of their InnoDB persistent statistics
 */
class TableStatsCheck implements CheckInterface
{
    public const CODE = 'TABLE_STATS';
    private const MB = 1048576;

    /**
     * @param ReadOnlyConnection $connection
     * @param string[] $tablePatterns LIKE patterns without table prefix
     * @param int $limit
     * @param int $largeTableMb
     * @param int $staleAfterDays
     */
    public function __construct(
        private readonly ReadOnlyConnection $connection,
        private readonly array $tablePatterns = [],
        private readonly int $limit = 10,
        private readonly int $largeTableMb = 100,
        private readonly int $staleAfterDays = 30
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
        // di.xml arrays have named keys; positional placeholders need a list
        $patterns = array_values(array_map(
            fn (string $pattern): string => $this->connection->getTableName($pattern),
            $this->tablePatterns
        ));
        $tables = $this->connection->fetchAll(
            sprintf(
                'SELECT TABLE_NAME, TABLE_ROWS, DATA_LENGTH + INDEX_LENGTH AS size FROM information_schema.TABLES '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND (%s) AND TABLE_NAME NOT LIKE \'%%\_cl\' '
                . 'ORDER BY size DESC LIMIT %d',
                implode(' OR ', array_fill(0, count($patterns), 'TABLE_NAME LIKE ?')),
                $this->limit
            ),
            $patterns
        );
        if (!$tables) {
            return [new CheckResult(Severity::Ok, self::CODE, 'No index or EAV tables found')];
        }

        $sizes = [];
        foreach ($tables as $table) {
            $sizes[(string)$table['TABLE_NAME']] = (int)round((int)$table['size'] / self::MB);
        }
        $large = array_filter($sizes, fn (int $sizeMb): bool => $sizeMb >= $this->largeTableMb);
        if (!$large) {
            return [
                new CheckResult(
                    Severity::Ok,
                    self::CODE,
                    sprintf(
                        'No index or EAV table reaches %d MB (largest: %s, %d MB)',
                        $this->largeTableMb,
                        array_key_first($sizes),
                        reset($sizes)
                    )
                ),
            ];
        }

        $statsAge = $this->fetchStatisticsAge(array_keys($large));
        $metrics = [];
        foreach ($tables as $table) {
            $name = (string)$table['TABLE_NAME'];
            $metrics[$name] = [
                'size_mb' => $sizes[$name],
                'rows' => (int)$table['TABLE_ROWS'],
                'stats_age_days' => isset($statsAge[$name]) ? (int)$statsAge[$name] : null,
            ];
        }

        if ($statsAge === null) {
            $described = [];
            foreach ($large as $name => $sizeMb) {
                $described[] = sprintf('%s (%d MB)', $name, $sizeMb);
            }
            return [
                new CheckResult(
                    Severity::Info,
                    self::CODE,
                    'Large index and EAV tables: ' . implode(', ', $described) . '. Statistics dates are not '
                    . 'available (no SELECT privilege on mysql.innodb_table_stats)',
                    'Run ANALYZE TABLE on these tables after large imports or catalog changes so the optimizer '
                    . 'uses current row estimates (the doctor never runs it).',
                    'ANALYZE TABLE ' . implode(', ', array_keys($large)) . ';',
                    ['tables' => $metrics]
                ),
            ];
        }

        $stale = [];
        foreach ($large as $name => $sizeMb) {
            $age = $metrics[$name]['stats_age_days'];
            if ($age === null || $age > $this->staleAfterDays) {
                $stale[$name] = sprintf('%s (%d MB, %s)', $name, $sizeMb, $age === null ? 'never' : $age . ' days');
            }
        }

        if ($stale) {
            return [
                new CheckResult(
                    Severity::Warn,
                    self::CODE,
                    'Stale or missing statistics on large tables: ' . implode(', ', $stale),
                    'After large imports or catalog changes the optimizer may use outdated row estimates and pick '
                    . 'slow plans for index queries. Run ANALYZE TABLE in a quiet period (the doctor never runs it).',
                    'ANALYZE TABLE ' . implode(', ', array_keys($stale)) . ';',
                    ['tables' => $metrics]
                ),
            ];
        }

        return [
            new CheckResult(
                Severity::Ok,
                self::CODE,
                sprintf('Statistics of the %d large index and EAV tables are recent', count($large)),
                '',
                '',
                ['tables' => $metrics]
            ),
        ];
    }

    /**
     * Days since the persistent statistics of each table were updated, null when not readable
     *
     * @param string[] $tables
     * @return array<string,string>|null
     */
    private function fetchStatisticsAge(array $tables): ?array
    {
        try {
            $pairs = $this->connection->fetchPairs(
                sprintf(
                    'SELECT table_name, TIMESTAMPDIFF(DAY, last_update, UTC_TIMESTAMP()) FROM mysql.innodb_table_stats '
                    . 'WHERE database_name = DATABASE() AND table_name IN (%s)',
                    implode(', ', array_fill(0, count($tables), '?'))
                ),
                $tables
            );
            $ages = [];
            foreach ($pairs as $table => $days) {
                $ages[(string)$table] = (string)$days;
            }
            return $ages;
        } catch (\Exception) {
            // Managed databases rarely grant SELECT on the mysql schema
            return null;
        }
    }
}
