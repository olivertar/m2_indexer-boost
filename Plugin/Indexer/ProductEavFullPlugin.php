<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Plugin\Indexer;

use Magento\Catalog\Model\Indexer\Product\Eav\Action\Full as CoreFull;
use Orangecat\IndexerBoost\Model\Indexer\ProductEav\Action\Full;
use Orangecat\IndexerBoost\Model\Indexer\ProductEav\Action\FullFactory;
use Orangecat\IndexerBoost\Model\Indexer\Switcher;

/**
 * Runs the Indexer Boost full catalog_product_attribute reindex when its flag is on
 */
class ProductEavFullPlugin
{
    public const INDEXER_ID = 'catalog_product_attribute';

    /**
     * @param Switcher $switcher
     * @param FullFactory $fullFactory
     */
    public function __construct(
        private readonly Switcher $switcher,
        private readonly FullFactory $fullFactory
    ) {
    }

    /**
     * Run the core or the Indexer Boost action
     *
     * @param CoreFull $subject
     * @param callable $proceed
     * @param int[]|int|null $ids
     * @return void
     */
    public function aroundExecute(CoreFull $subject, callable $proceed, $ids = null): void
    {
        // Our action extends the core one, so this plugin intercepts it too: let it run its own body
        if ($subject instanceof Full) {
            $proceed($ids);
            return;
        }
        $this->switcher->run(
            self::INDEXER_ID,
            fn () => $this->fullFactory->create()->execute($ids),
            fn () => $proceed($ids)
        );
    }
}
