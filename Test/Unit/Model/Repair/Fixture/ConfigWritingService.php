<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Repair\Fixture;

use Magento\Framework\App\Config\Storage\WriterInterface;

/**
 * Control case for RepairWritesNoConfigTest: an own class that can write core_config_data
 */
class ConfigWritingService
{
    /**
     * @param WriterInterface $configWriter
     */
    public function __construct(
        private readonly WriterInterface $configWriter
    ) {
    }

    /**
     * Writer the class could save configuration with
     *
     * @return WriterInterface
     */
    public function getConfigWriter(): WriterInterface
    {
        return $this->configWriter;
    }
}
