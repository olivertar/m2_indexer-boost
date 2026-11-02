<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Repair;

/**
 * An indexer left in "working" for too long, with the verdict of the stuck detection
 */
class StuckIndexer
{
    /**
     * Lock free and no running statement on its tables: safe to invalidate
     */
    public const STUCK = 'stuck';

    /**
     * Lock free, but running statements cannot be listed (no PROCESS privilege)
     */
    public const DOUBTFUL = 'doubtful';

    /**
     * The INDEXER<id> lock is held: a reindex is running
     */
    public const RUNNING = 'running';

    /**
     * Lock free, but a statement is writing its tables (e.g. a multi-process reindex that lost the lock)
     */
    public const ACTIVE = 'active';

    /**
     * The application lock is disabled, so a running reindex cannot be told from a dead one
     */
    public const UNVERIFIABLE = 'unverifiable';

    /**
     * @param string $indexerId
     * @param int $minutesWorking minutes since indexer_state.updated
     * @param string $verdict one of the constants of this class
     * @param string|null $statement running statement that proves activity (verdict ACTIVE)
     */
    public function __construct(
        public readonly string $indexerId,
        public readonly int $minutesWorking,
        public readonly string $verdict,
        public readonly ?string $statement = null
    ) {
    }
}
