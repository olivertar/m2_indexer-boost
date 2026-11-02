<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Indexer\Price;

/**
 * Marks the core full catalog_product_price reindex that Indexer Boost runs, so its query plugin applies only there
 */
class FullReindexScope
{
    /**
     * @var bool
     */
    private bool $active = false;

    /**
     * Run the callable inside the scope
     *
     * @param callable $reindex
     * @return mixed what the callable returned
     */
    public function run(callable $reindex): mixed
    {
        $this->active = true;
        try {
            return $reindex();
        } finally {
            $this->active = false;
        }
    }

    /**
     * Whether a full reindex is running inside the scope
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->active;
    }
}
