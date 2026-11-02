<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Doctor\Check;

use Magento\Framework\App\DeploymentConfig;
use Orangecat\IndexerBoost\Model\Doctor\CheckInterface;
use Orangecat\IndexerBoost\Model\Doctor\CheckResult;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use Orangecat\IndexerBoost\Model\Doctor\Severity;

/**
 * Whether indexer/use_application_lock is enabled in env.php
 */
class ApplicationLockCheck implements CheckInterface
{
    public const CODE = 'APPLICATION_LOCK';
    private const CONFIG_PATH = 'indexer/use_application_lock';

    /**
     * @param DeploymentConfig $deploymentConfig
     */
    public function __construct(
        private readonly DeploymentConfig $deploymentConfig
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
        if ((bool)$this->deploymentConfig->get(self::CONFIG_PATH, false)) {
            return [new CheckResult(Severity::Ok, self::CODE, 'Indexer application lock is enabled')];
        }

        return [
            new CheckResult(
                Severity::Warn,
                self::CODE,
                self::CONFIG_PATH . ' is not enabled in app/etc/env.php',
                'Without it, a reindex that dies halfway (out of memory, killed process, deployment) stays '
                . '"working" forever and cron never rebuilds that index. With it, Magento holds a lock while '
                . 'reindexing and treats a "working" indexer without lock as invalid.',
                "app/etc/env.php: 'indexer' => ['use_application_lock' => true]",
                ['use_application_lock' => false]
            ),
        ];
    }
}
