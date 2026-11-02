<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Doctor\Check;

use Magento\Framework\Indexer\ConfigInterface;
use Orangecat\IndexerBoost\Model\Doctor\CheckInterface;
use Orangecat\IndexerBoost\Model\Doctor\CheckResult;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use Orangecat\IndexerBoost\Model\Doctor\Severity;

/**
 * Data exporter indexers of Adobe Commerce Services (*_data_exporter)
 */
class DataExportersCheck implements CheckInterface
{
    public const CODE = 'DATA_EXPORTERS';

    /**
     * @param ConfigInterface $indexerConfig
     */
    public function __construct(
        private readonly ConfigInterface $indexerConfig
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
        $indexers = [];
        $modules = [];
        foreach ($this->indexerConfig->getIndexers() as $indexerId => $indexer) {
            if (!str_ends_with((string)$indexerId, '_data_exporter')) {
                continue;
            }
            $indexers[] = (string)$indexerId;
            $parts = explode('\\', ltrim((string)($indexer['action_class'] ?? ''), '\\'));
            if (count($parts) > 2) {
                $modules[$parts[0] . '_' . $parts[1]] = true;
            }
        }

        if (!$indexers) {
            return [
                new CheckResult(
                    Severity::Ok,
                    self::CODE,
                    'No Adobe Commerce Services data exporter indexers installed'
                ),
            ];
        }

        $modules = array_keys($modules);
        return [
            new CheckResult(
                Severity::Info,
                self::CODE,
                sprintf('%d data exporter indexer(s): %s', count($indexers), implode(', ', $indexers)),
                'These indexers feed Adobe Commerce Services (Catalog Service, Live Search, Product '
                . 'Recommendations, Payment Services...). If none of those services is used, they reindex for '
                . 'nothing: disable their modules after checking dependencies.',
                $modules ? 'bin/magento module:disable ' . implode(' ', $modules) : '',
                ['indexers' => $indexers, 'modules' => $modules]
            ),
        ];
    }
}
