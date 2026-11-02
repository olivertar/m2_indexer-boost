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
use Orangecat\IndexerBoost\Model\Doctor\System\SystemInfo;

/**
 * MAGE_INDEXER_THREADS_COUNT and pcntl availability for parallel full reindex
 */
class ParallelThreadsCheck implements CheckInterface
{
    public const CODE = 'PARALLEL_THREADS';
    private const ENV_THREADS = 'MAGE_INDEXER_THREADS_COUNT';

    /**
     * @param SystemInfo $systemInfo
     * @param CatalogSizeProvider $catalogSizeProvider
     * @param int $warnFromProducts
     * @param int|string|null $threadsCount Same init parameter that Magento\Indexer\Model\ProcessManager receives
     */
    public function __construct(
        private readonly SystemInfo $systemInfo,
        private readonly CatalogSizeProvider $catalogSizeProvider,
        private readonly int $warnFromProducts = 5000,
        private readonly int|string|null $threadsCount = null
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
     * CLI only: the thread count comes from the process environment, which differs between PHP-FPM and CLI/cron
     *
     * @return bool
     */
    public function isHeavy(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function check(DoctorContext $context): array
    {
        $threads = $this->threadsCount === null || $this->threadsCount === '' ? null : (int)$this->threadsCount;
        $pcntl = $this->systemInfo->isPcntlAvailable();
        $cores = $this->systemInfo->getCpuCores();
        $products = $this->catalogSizeProvider->get($context)->getProductCount();

        $suggested = sprintf('export %s=%d', self::ENV_THREADS, max(2, (int)$cores - 1));
        $metrics = ['threads' => $threads, 'cpu_cores' => $cores, 'pcntl' => $pcntl];
        $coresText = $cores === null ? 'unknown' : (string)$cores;

        if (!$pcntl) {
            return [
                new CheckResult(
                    $threads > 1 ? Severity::Warn : Severity::Info,
                    self::CODE,
                    $threads > 1
                        ? sprintf('%s=%d is ignored: pcntl is not available', self::ENV_THREADS, $threads)
                        : 'Parallel reindex is not possible: pcntl is not available',
                    'Parallel reindex forks processes with pcntl, which is not available in this PHP CLI. '
                    . 'Enable the extension for the CLI used by cron and bin/magento.',
                    'Install/enable the pcntl extension for PHP CLI (php -m | grep pcntl)',
                    $metrics
                ),
            ];
        }

        if ($cores !== null && $threads > $cores) {
            return [
                new CheckResult(
                    Severity::Warn,
                    self::CODE,
                    sprintf('%s=%d is higher than the %d CPU cores available', self::ENV_THREADS, $threads, $cores),
                    'More processes than cores compete for CPU and database connections and usually make the '
                    . 'reindex slower. Keep one core free for the database and web traffic.',
                    $suggested,
                    $metrics
                ),
            ];
        }

        if ($threads > 1) {
            return [
                new CheckResult(
                    Severity::Ok,
                    self::CODE,
                    sprintf('%s=%d with pcntl enabled (%s CPU cores)', self::ENV_THREADS, $threads, $coresText)
                ),
            ];
        }

        return [
            new CheckResult(
                $products >= $this->warnFromProducts ? Severity::Warn : Severity::Info,
                self::CODE,
                sprintf(
                    '%s is not set: full reindex runs in a single process (%s CPU cores available, pcntl enabled)',
                    self::ENV_THREADS,
                    $coresText
                ),
                'Indexers that support it (price, catalog search, category products) split a full reindex '
                . 'across processes. Set the variable in the environment of the shell and of the cron user, '
                . 'then measure.',
                $suggested,
                $metrics
            ),
        ];
    }
}
