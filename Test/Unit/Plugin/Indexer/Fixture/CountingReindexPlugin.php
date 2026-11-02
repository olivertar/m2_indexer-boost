<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Plugin\Indexer\Fixture;

use Magento\Framework\Indexer\IndexerInterface;

/**
 * Around plugin that counts how many times it is reached
 */
class CountingReindexPlugin
{
    /**
     * @var int
     */
    public int $calls = 0;

    /**
     * Count the call and proceed
     *
     * @param IndexerInterface $subject
     * @param callable $proceed
     * @return mixed
     */
    public function aroundReindexAll(IndexerInterface $subject, callable $proceed): mixed
    {
        $this->calls++;
        return $proceed();
    }
}
