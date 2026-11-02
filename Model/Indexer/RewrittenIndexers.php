<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Indexer;

/**
 * Indexers Indexer Boost has its own implementation of, declared in di.xml
 */
class RewrittenIndexers
{
    /**
     * @param string[] $indexerIds
     */
    public function __construct(
        private readonly array $indexerIds = []
    ) {
    }

    /**
     * Indexer IDs, sorted
     *
     * @return string[]
     */
    public function getIds(): array
    {
        $ids = array_values($this->indexerIds);
        sort($ids);
        return $ids;
    }

    /**
     * Whether an indexer has an Indexer Boost implementation
     *
     * @param string $indexerId
     * @return bool
     */
    public function isRewritten(string $indexerId): bool
    {
        return in_array($indexerId, $this->indexerIds, true);
    }
}
