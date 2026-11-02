<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Doctor\Fixture;

use Orangecat\IndexerBoost\Model\Doctor\CheckInterface;
use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;

/**
 * Misbehaving check: sends a write through the read-only connection and hides the rejection
 */
class SwallowedWriteCheck implements CheckInterface
{
    /**
     * @param ReadOnlyConnection $connection
     */
    public function __construct(
        private readonly ReadOnlyConnection $connection
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getCode(): string
    {
        return 'SWALLOWED_WRITE';
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
        try {
            $this->connection->fetchOne('DELETE FROM ' . $this->connection->getTableName('indexer_state'));
        } catch (\Exception) {
            return [];
        }
        return [];
    }
}
