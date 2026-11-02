<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Integration\Fixture;

use Magento\Framework\App\DeploymentConfig;

/**
 * Deployment config of the test installation with the indexer application lock switched on
 */
class ApplicationLockDeploymentConfig extends DeploymentConfig
{
    private const PATH = 'indexer/use_application_lock';

    /**
     * @inheritdoc
     */
    public function get($key = null, $defaultValue = null)
    {
        return $key === self::PATH ? true : parent::get($key, $defaultValue);
    }
}
