<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Repair;

/**
 * Result of one full reindex run by the repair
 */
class ReindexOutcome
{
    /**
     * @param string $indexerId
     * @param float $seconds
     * @param string|null $error null when the reindex succeeded
     */
    public function __construct(
        public readonly string $indexerId,
        public readonly float $seconds,
        public readonly ?string $error = null
    ) {
    }
}
