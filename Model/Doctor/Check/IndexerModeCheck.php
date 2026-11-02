<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Doctor\Check;

use Magento\Framework\Indexer\ConfigInterface;
use Orangecat\IndexerBoost\Model\Doctor\Catalog\CatalogSizeProvider;
use Orangecat\IndexerBoost\Model\Doctor\CheckInterface;
use Orangecat\IndexerBoost\Model\Doctor\CheckResult;
use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use Orangecat\IndexerBoost\Model\Doctor\Severity;

/**
 * Indexers left in "Update on Save" (realtime) mode
 */
class IndexerModeCheck implements CheckInterface
{
    public const CODE = 'INDEXER_MODE';

    /**
     * @param ConfigInterface $indexerConfig
     * @param ReadOnlyConnection $connection
     * @param CatalogSizeProvider $catalogSizeProvider
     * @param int $warnFromProducts
     * @param string[] $excludedIndexers
     * @param string[] $importReindexed realtime indexers the core import reindexes for every batch of rows
     * @param string[] $importInvalidated realtime indexers the core import invalidates when it finishes
     */
    public function __construct(
        private readonly ConfigInterface $indexerConfig,
        private readonly ReadOnlyConnection $connection,
        private readonly CatalogSizeProvider $catalogSizeProvider,
        private readonly int $warnFromProducts = 5000,
        private readonly array $excludedIndexers = [],
        private readonly array $importReindexed = [],
        private readonly array $importInvalidated = []
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
        $viewModes = $this->connection->fetchPairs(sprintf(
            'SELECT view_id, mode FROM %s',
            $this->connection->getTableName('mview_state')
        ));

        $realtime = [];
        foreach ($this->indexerConfig->getIndexers() as $indexerId => $indexer) {
            $viewId = (string)($indexer['view_id'] ?? '');
            if ($viewId === '' || in_array($indexerId, $this->excludedIndexers, true)) {
                // Without an mview view the indexer cannot run on schedule
                continue;
            }
            if (($viewModes[$viewId] ?? 'disabled') !== 'enabled') {
                $realtime[] = (string)$indexerId;
            }
        }

        if (!$realtime) {
            return [new CheckResult(Severity::Ok, self::CODE, 'All indexers are in "Update by Schedule" mode')];
        }

        $products = $this->catalogSizeProvider->get($context)->getProductCount();
        $metrics = ['realtime_indexers' => $realtime, 'products' => $products];
        $import = $this->importEffects($realtime);
        if ($import !== null) {
            $metrics['import'] = $import;
        }

        return [
            new CheckResult(
                $products >= $this->warnFromProducts ? Severity::Warn : Severity::Info,
                self::CODE,
                sprintf(
                    '%d indexer(s) in "Update on Save" mode: %s',
                    count($realtime),
                    implode(', ', $realtime)
                ),
                'Every product save reindexes synchronously, which slows down the admin, imports and API calls. '
                . 'Switch to "Update by Schedule" so changes are processed by cron.'
                . ($import === null ? '' : $this->importAdvice($import)),
                'bin/magento indexer:set-mode schedule ' . implode(' ', $realtime),
                $metrics
            ),
        ];
    }

    /**
     * What a core import does to each realtime indexer, or null when the import behaviour is not configured
     *
     * The import writes rows with SQL instead of saving products, so realtime indexers only see it through the
     * explicit reindex and invalidation calls of the core; the others keep stale rows.
     *
     * @param string[] $realtime
     * @return array<string,string[]>|null
     */
    private function importEffects(array $realtime): ?array
    {
        if (!$this->importReindexed && !$this->importInvalidated) {
            return null;
        }
        $reindexed = array_values(array_intersect($realtime, $this->importReindexed));
        $invalidated = array_values(array_diff(array_intersect($realtime, $this->importInvalidated), $reindexed));
        return [
            'reindexed_per_batch' => $reindexed,
            'invalidated' => $invalidated,
            'not_refreshed' => array_values(array_diff($realtime, $reindexed, $invalidated)),
        ];
    }

    /**
     * Sentence on the import effects, naming only the groups that have indexers
     *
     * @param array<string,string[]> $import
     * @return string
     */
    private function importAdvice(array $import): string
    {
        $templates = [
            'reindexed_per_batch' => 'reindex %s synchronously for every batch of rows',
            'invalidated' => 'invalidate %s so cron then runs their full reindexes one after another',
            'not_refreshed' => 'leave %s stale until their next full reindex',
        ];
        $parts = [];
        foreach ($templates as $key => $template) {
            if ($import[$key]) {
                $parts[] = sprintf($template, implode(', ', $import[$key]));
            }
        }
        $last = array_pop($parts);
        return ' Imports make it worse: they ' . ($parts ? implode(', ', $parts) . ', and ' : '') . $last . '.';
    }
}
