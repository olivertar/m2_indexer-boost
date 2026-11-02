<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Integration;

use Magento\Framework\Console\CommandListInterface;
use Magento\Framework\Module\ModuleList;
use Magento\TestFramework\Helper\Bootstrap;
use Orangecat\IndexerBoost\Console\Command\DoctorCommand;
use PHPUnit\Framework\TestCase;

class ModuleConfigTest extends TestCase
{
    public function testModuleIsEnabled(): void
    {
        $moduleList = Bootstrap::getObjectManager()->get(ModuleList::class);

        $this->assertTrue($moduleList->has('Orangecat_IndexerBoost'));
    }

    public function testDoctorCommandIsRegistered(): void
    {
        $commands = Bootstrap::getObjectManager()->get(CommandListInterface::class)->getCommands();

        $this->assertInstanceOf(DoctorCommand::class, $commands['indexer_boost_doctor'] ?? null);
    }
}
