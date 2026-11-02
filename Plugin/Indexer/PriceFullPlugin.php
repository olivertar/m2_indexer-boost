<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Plugin\Indexer;

use Magento\Catalog\Model\Indexer\Product\Price\Action\Full as CoreFull;
use Orangecat\IndexerBoost\Model\Indexer\Price\FullReindexScope;
use Orangecat\IndexerBoost\Model\Indexer\Switcher;

/**
 * Runs the core full catalog_product_price reindex inside the Indexer Boost scope when its flag is on
 */
class PriceFullPlugin
{
    public const INDEXER_ID = 'catalog_product_price';

    /**
     * @param Switcher $switcher
     * @param FullReindexScope $scope
     */
    public function __construct(
        private readonly Switcher $switcher,
        private readonly FullReindexScope $scope
    ) {
    }

    /**
     * Run the core action, inside the scope when the flag is on
     *
     * @param CoreFull $subject
     * @param callable $proceed
     * @param int[]|int|null $ids
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundExecute(CoreFull $subject, callable $proceed, $ids = null): void
    {
        $this->switcher->run(
            self::INDEXER_ID,
            fn () => $this->scope->run(fn () => $proceed($ids)),
            fn () => $proceed($ids)
        );
    }
}
