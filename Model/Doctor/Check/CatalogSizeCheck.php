<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Doctor\Check;

use Orangecat\IndexerBoost\Model\Doctor\Catalog\CatalogSizeProvider;
use Orangecat\IndexerBoost\Model\Doctor\CheckInterface;
use Orangecat\IndexerBoost\Model\Doctor\CheckResult;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use Orangecat\IndexerBoost\Model\Doctor\Severity;

/**
 * Products per type, categories, stores, websites, customer groups and catalog rules
 */
class CatalogSizeCheck implements CheckInterface
{
    public const CODE = 'CATALOG_SIZE';

    /**
     * @param CatalogSizeProvider $catalogSizeProvider
     */
    public function __construct(
        private readonly CatalogSizeProvider $catalogSizeProvider
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getCode(): string
    {
        return self::CODE;
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
        $size = $this->catalogSizeProvider->get($context)->toArray();
        $types = [];
        foreach ($size['products_by_type'] as $type => $count) {
            $types[] = $type . ': ' . $count;
        }

        $message = sprintf(
            '%d products (%s), %d categories, %d store views, %d websites, %d customer groups, '
            . '%d of %d catalog rules active',
            $size['products'],
            implode(', ', $types),
            $size['categories'],
            $size['stores'],
            $size['websites'],
            $size['customer_groups'],
            $size['active_catalog_rules'],
            $size['catalog_rules']
        );

        return [
            new CheckResult(
                Severity::Info,
                self::CODE,
                $message,
                'Context for the other checks and for benchmark comparisons.',
                '',
                $size
            ),
        ];
    }
}
