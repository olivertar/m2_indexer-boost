<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\ResourceModel;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Writes of the passive run log (reads go through Monitor\RunStatsReader)
 */
class RunLog extends AbstractDb
{
    public const TABLE = 'orangecat_indexer_boost_run_log';

    /**
     * @inheritdoc
     */
    protected function _construct()
    {
        $this->_init(self::TABLE, 'run_id');
    }

    /**
     * Store one indexer run
     *
     * @param string $indexerId
     * @param string $kind full|changes
     * @param \DateTimeImmutable $startedAt stored in UTC
     * @param float $durationSeconds
     * @param string|null $error null for a successful run
     * @return void
     */
    public function saveRun(
        string $indexerId,
        string $kind,
        \DateTimeImmutable $startedAt,
        float $durationSeconds,
        ?string $error
    ): void {
        $this->connection()->insert($this->getMainTable(), [
            'indexer_id' => $indexerId,
            'kind' => $kind,
            'started_at' => $this->toUtc($startedAt),
            'duration_s' => number_format($durationSeconds, 3, '.', ''),
            'failed' => $error === null ? 0 : 1,
            'error' => $error,
        ]);
    }

    /**
     * Delete runs started before the given moment
     *
     * @param \DateTimeImmutable $before
     * @return int deleted rows
     */
    public function deleteOlderThan(\DateTimeImmutable $before): int
    {
        return $this->connection()->delete($this->getMainTable(), ['started_at < ?' => $this->toUtc($before)]);
    }

    /**
     * DB adapter of the resource
     *
     * @return AdapterInterface
     * @throws \RuntimeException
     */
    private function connection(): AdapterInterface
    {
        $connection = $this->getConnection();
        if (!$connection instanceof AdapterInterface) {
            throw new \RuntimeException('No database connection for ' . self::TABLE);
        }
        return $connection;
    }

    /**
     * Format a moment as a UTC datetime, the timezone Magento stores dates in
     *
     * @param \DateTimeImmutable $moment
     * @return string
     */
    private function toUtc(\DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
