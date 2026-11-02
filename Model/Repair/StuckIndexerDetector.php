<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Repair;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Lock\LockManagerInterface;
use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;

/**
 * Finds indexers whose raw indexer_state.status has been "working" for too long and tells whether they are stuck.
 *
 * The raw status is read with SQL: with the application lock enabled the core State model already reports a
 * "working" indexer without lock as invalid, which would hide the case.
 */
class StuckIndexerDetector
{
    /**
     * Same prefix as Magento\Indexer\Model\Indexer\State
     */
    private const LOCK_PREFIX = 'INDEXER';

    private const APPLICATION_LOCK_PATH = 'indexer/use_application_lock';

    /**
     * @param ReadOnlyConnection $connection
     * @param LockManagerInterface $lockManager
     * @param DeploymentConfig $deploymentConfig
     * @param ProcessListReader $processList
     * @param array<string,string[]> $indexTables indexer id => tables (or table prefixes) its full reindex writes
     * @param int $stuckAfterMinutes
     */
    public function __construct(
        private readonly ReadOnlyConnection $connection,
        private readonly LockManagerInterface $lockManager,
        private readonly DeploymentConfig $deploymentConfig,
        private readonly ProcessListReader $processList,
        private readonly array $indexTables = [],
        private readonly int $stuckAfterMinutes = 120
    ) {
    }

    /**
     * Indexers working for longer than the threshold, by indexer id
     *
     * @return array<string,StuckIndexer>
     */
    public function detect(): array
    {
        $rows = $this->connection->fetchAll(
            sprintf(
                'SELECT indexer_id, TIMESTAMPDIFF(MINUTE, updated, UTC_TIMESTAMP()) AS minutes FROM %s '
                . "WHERE status = 'working' AND updated < UTC_TIMESTAMP() - INTERVAL ? MINUTE ORDER BY indexer_id",
                $this->connection->getTableName('indexer_state')
            ),
            [$this->stuckAfterMinutes]
        );

        $applicationLock = (bool)$this->deploymentConfig->get(self::APPLICATION_LOCK_PATH, false);
        $statements = null;
        $privilege = null;
        $result = [];
        foreach ($rows as $row) {
            $indexerId = (string)$row['indexer_id'];
            $minutes = (int)$row['minutes'];
            if (!$applicationLock) {
                $result[$indexerId] = new StuckIndexer($indexerId, $minutes, StuckIndexer::UNVERIFIABLE);
                continue;
            }
            if ($this->lockManager->isLocked(self::LOCK_PREFIX . $indexerId)) {
                $result[$indexerId] = new StuckIndexer($indexerId, $minutes, StuckIndexer::RUNNING);
                continue;
            }
            $statements ??= $this->processList->getWriteStatements();
            $statement = $this->findStatement($indexerId, $statements);
            if ($statement !== null) {
                $result[$indexerId] = new StuckIndexer($indexerId, $minutes, StuckIndexer::ACTIVE, $statement);
                continue;
            }
            // Without PROCESS only our own connections are listed: no statement found proves nothing
            $privilege ??= $this->processList->hasProcessPrivilege();
            $result[$indexerId] = new StuckIndexer(
                $indexerId,
                $minutes,
                $privilege ? StuckIndexer::STUCK : StuckIndexer::DOUBTFUL
            );
        }
        return $result;
    }

    /**
     * Indexers whose raw status is "working", however long
     *
     * @return string[]
     */
    public function getWorkingIndexerIds(): array
    {
        return array_map('strval', $this->connection->fetchCol(sprintf(
            "SELECT indexer_id FROM %s WHERE status = 'working'",
            $this->connection->getTableName('indexer_state')
        )));
    }

    /**
     * First running statement that writes one of the indexer tables
     *
     * @param string $indexerId
     * @param string[] $statements
     * @return string|null
     */
    private function findStatement(string $indexerId, array $statements): ?string
    {
        $tables = array_map(
            fn (string $table): string => strtolower($this->connection->getTableName($table)),
            $this->indexTables[$indexerId] ?? [$indexerId]
        );
        foreach ($statements as $statement) {
            $lower = strtolower($statement);
            foreach ($tables as $table) {
                if (str_contains($lower, $table)) {
                    return $statement;
                }
            }
        }
        return null;
    }
}
