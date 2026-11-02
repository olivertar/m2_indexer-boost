<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Doctor\Check;

use Orangecat\IndexerBoost\Model\Doctor\CheckInterface;
use Orangecat\IndexerBoost\Model\Doctor\CheckResult;
use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use Orangecat\IndexerBoost\Model\Doctor\Severity;

/**
 * Pending changelog entries per mview view and size of each *_cl table.
 *
 * Uses MIN/MAX(version_id) (primary key) instead of COUNT(*) so huge changelogs are not scanned.
 */
class MviewBacklogCheck implements CheckInterface
{
    public const CODE = 'MVIEW_BACKLOG';

    /**
     * @param ReadOnlyConnection $connection
     * @param int $warnPendingEntries
     * @param int $largeChangelogRows
     */
    public function __construct(
        private readonly ReadOnlyConnection $connection,
        private readonly int $warnPendingEntries = 10000,
        private readonly int $largeChangelogRows = 1000000
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
        $versions = $this->connection->fetchPairs(sprintf(
            "SELECT view_id, version_id FROM %s WHERE mode = 'enabled'",
            $this->connection->getTableName('mview_state')
        ));
        $existingTables = $this->connection->fetchCol(
            'SELECT TABLE_NAME FROM information_schema.TABLES '
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '%\\_cl'"
        );

        $pending = [];
        $rows = [];
        $checked = 0;
        foreach ($versions as $viewId => $versionId) {
            $table = $this->connection->getTableName($viewId . '_cl');
            if (!in_array($table, $existingTables, true)) {
                continue;
            }
            $checked++;
            $range = $this->connection->fetchRow(
                sprintf('SELECT MIN(version_id) AS min_id, MAX(version_id) AS max_id FROM %s', $table)
            ) ?: [];
            if (($range['max_id'] ?? null) === null) {
                continue;
            }
            $pending[(string)$viewId] = max(0, (int)$range['max_id'] - (int)$versionId);
            $rows[(string)$viewId] = (int)$range['max_id'] - (int)$range['min_id'] + 1;
        }

        $results = [];
        $backlog = array_filter($pending, fn (int $count): bool => $count >= $this->warnPendingEntries);
        if ($backlog) {
            $results[] = new CheckResult(
                Severity::Warn,
                self::CODE,
                'Changelog backlog: ' . $this->describe($backlog, '%s (%d pending)'),
                'These entries wait for the indexer_update_all_views cron job. A growing backlog means the index '
                . 'cron group is not running or cannot keep up, and the storefront shows stale data meanwhile.',
                'bin/magento cron:run --group=index (then check CRON_INDEXER)',
                ['pending' => $backlog]
            );
        }

        $large = array_filter($rows, fn (int $count): bool => $count >= $this->largeChangelogRows);
        if ($large) {
            $results[] = new CheckResult(
                Severity::Info,
                self::CODE,
                'Large changelog tables: ' . $this->describe($large, '%s (~%d rows)'),
                'Processed entries are removed by the indexer_clean_all_changelogs cron job. A very large '
                . 'changelog slows down every mview run and wastes disk.',
                'Check that indexer_clean_all_changelogs runs: bin/magento cron:run --group=index',
                ['rows' => $large]
            );
        }

        return $results ?: [
            new CheckResult(
                Severity::Ok,
                self::CODE,
                sprintf(
                    'No changelog backlog (%d %s checked, %d entries pending in total)',
                    $checked,
                    $checked === 1 ? 'view' : 'views',
                    array_sum($pending)
                ),
                '',
                '',
                ['pending' => $pending]
            ),
        ];
    }

    /**
     * Describe view counts, e.g. "catalog_product_price (10 pending)"
     *
     * @param array<string,int> $counts
     * @param string $format
     * @return string
     */
    private function describe(array $counts, string $format): string
    {
        $parts = [];
        foreach ($counts as $viewId => $count) {
            $parts[] = sprintf($format, $viewId, $count);
        }
        return implode(', ', $parts);
    }
}
