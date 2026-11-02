<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Repair;

use Magento\Framework\App\Config\ConfigResource\ConfigInterface as ConfigResource;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\DeploymentConfig\Writer;
use Orangecat\IndexerBoost\Console\Command\DoctorCommand;
use Orangecat\IndexerBoost\Model\Repair\Repairer;
use Orangecat\IndexerBoost\Test\Unit\Model\Repair\Fixture\ConfigWritingService;
use PHPUnit\Framework\TestCase;

/**
 * --repair never changes configuration: nothing it depends on can write env.php or core_config_data
 */
class RepairWritesNoConfigTest extends TestCase
{
    /**
     * Services able to write configuration
     */
    private const CONFIG_WRITERS = [WriterInterface::class, Writer::class, ConfigResource::class];

    public function testNothingTheRepairDependsOnCanWriteConfiguration(): void
    {
        $this->assertSame([], $this->configWriters(Repairer::class));
        $this->assertSame([], $this->configWriters(DoctorCommand::class));
    }

    public function testControlOwnClassDependingOnAConfigWriterIsCaught(): void
    {
        $this->assertSame(
            [ConfigWritingService::class . ' -> ' . WriterInterface::class],
            $this->configWriters(ConfigWritingService::class)
        );
    }

    /**
     * Constructor dependencies that write configuration, followed through the own classes of the module
     *
     * @param string $class
     * @param string[] $seen
     * @return string[] "class -> writer" pairs
     */
    private function configWriters(string $class, array &$seen = []): array
    {
        if (in_array($class, $seen, true)) {
            return [];
        }
        $seen[] = $class;
        if (!class_exists($class)) {
            return [];
        }
        $constructor = (new \ReflectionClass($class))->getConstructor();
        if ($constructor === null) {
            return [];
        }

        $found = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }
            $dependency = $type->getName();
            foreach (self::CONFIG_WRITERS as $writer) {
                if (is_a($dependency, $writer, true)) {
                    $found[] = $class . ' -> ' . $writer;
                }
            }
            if (str_starts_with($dependency, 'Orangecat\\')) {
                array_push($found, ...$this->configWriters($dependency, $seen));
            }
        }
        return $found;
    }
}
