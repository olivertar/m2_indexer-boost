<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Doctor\Fixture;

use Magento\Framework\App\ResourceConnection;
use Orangecat\IndexerBoost\Model\Doctor\CheckInterface;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;

/**
 * Misbehaving check: bypasses the read-only connection and writes through the adapter
 */
class DirectAdapterCheck implements CheckInterface
{
    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getCode(): string
    {
        return 'DIRECT_ADAPTER';
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
            $this->resource->getConnection()->insert('indexer_state', ['indexer_id' => 'x']);
        } catch (\Exception) {
            return [];
        }
        return [];
    }
}
