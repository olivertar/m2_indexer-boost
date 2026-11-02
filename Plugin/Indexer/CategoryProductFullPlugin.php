<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Plugin\Indexer;

use Magento\Catalog\Model\Indexer\Category\Product\Action\Full as CoreFull;
use Orangecat\IndexerBoost\Model\Indexer\CategoryProduct\Action\Full;
use Orangecat\IndexerBoost\Model\Indexer\CategoryProduct\Action\FullFactory;
use Orangecat\IndexerBoost\Model\Indexer\Switcher;

/**
 * Runs the Indexer Boost full catalog_category_product reindex when its flag is on
 *
 * Both catalog_category_product and catalog_product_category run this core action for a full reindex, so one flag,
 * the catalog_category_product one, covers the two.
 */
class CategoryProductFullPlugin
{
    public const INDEXER_ID = 'catalog_category_product';

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
     * @return mixed
     */
    public function aroundExecute(CoreFull $subject, callable $proceed): mixed
    {
        // Our action extends the core one, so this plugin intercepts it too: let it run its own body
        if ($subject instanceof Full) {
            return $proceed();
        }
        return $this->switcher->run(
            self::INDEXER_ID,
            fn () => $this->fullFactory->create()->execute(),
            $proceed
        );
    }
}
