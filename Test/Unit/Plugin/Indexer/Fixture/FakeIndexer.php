<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Plugin\Indexer\Fixture;

use Magento\Indexer\Model\Indexer;

/**
 * Concrete indexer whose full reindex only counts calls
 */
class FakeIndexer extends Indexer
{
    /**
     * @var int
     */
    public int $fullRuns = 0;

    /**
     * Parent constructor is skipped on purpose: nothing of the real indexer is used
     *
     * @param string $indexerId
     */
    public function __construct(
        private readonly string $indexerId
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getId()
    {
        return $this->indexerId;
    }

    /**
     * @inheritdoc
     */
    public function reindexAll()
    {
        $this->fullRuns++;
    }
}
