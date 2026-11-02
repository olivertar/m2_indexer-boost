<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Indexer;

use Orangecat\IndexerBoost\Model\Config;
use Orangecat\IndexerBoost\Model\Indexer\PreparationException;
use Orangecat\IndexerBoost\Model\Indexer\Switcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SwitcherTest extends TestCase
{
    /**
     * @var string[] implementations run, in order
     */
    private array $ran = [];

    public function testCoreRunsWhileTheIndexerIsOff(): void
    {
        $result = $this->switcher(false)->run('catalog_category_product', $this->boost(), $this->core());

        $this->assertSame('core', $result);
        $this->assertSame(['core'], $this->ran);
    }

    public function testFailedPreparationFallsBackToTheCoreAndIsLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(
            'Indexer Boost: preparing catalog_category_product failed, running the core indexer instead: '
            . 'snapshot table missing',
            $this->callback(static fn (array $context): bool => $context['exception'] instanceof PreparationException)
        );

        $result = $this->switcher(true, true, $logger)->run(
            'catalog_category_product',
            $this->boost(new PreparationException('snapshot table missing')),
            $this->core()
        );

        $this->assertSame('core', $result);
        $this->assertSame(['boost', 'core'], $this->ran);
    }

    #[DataProvider('failureProvider')]
    public function testFailureIsRethrownWithoutRunningTheCore(\Throwable $failure, bool $fallback): void
    {
        try {
            $this->switcher(true, $fallback)->run('catalog_category_product', $this->boost($failure), $this->core());
            $this->fail('The failure was swallowed');
        } catch (\Throwable $e) {
            $this->assertSame($failure, $e);
        }
        $this->assertSame(['boost'], $this->ran);
    }

    /**
     * @return array<string,array{\Throwable,bool}>
     */
    public static function failureProvider(): array
    {
        return [
            // The index may be half written: the core would not repair it, the next full reindex does
            'while writing' => [new \RuntimeException('Deadlock found'), true],
            'preparation without fallback' => [new PreparationException('snapshot table missing'), false],
        ];
    }

    /**
     * Switcher over a fake configuration
     *
     * @param bool $enabled
     * @param bool $fallback
     * @param LoggerInterface|null $logger
     * @return Switcher
     */
    private function switcher(bool $enabled, bool $fallback = true, ?LoggerInterface $logger = null): Switcher
    {
        $config = $this->createStub(Config::class);
        $config->method('isIndexerEnabled')->willReturn($enabled);
        $config->method('isFallbackOnError')->willReturn($fallback);
        return new Switcher($config, $logger ?? $this->createStub(LoggerInterface::class));
    }

    /**
     * Indexer Boost implementation, failing with the given exception
     *
     * @param \Throwable|null $failure
     * @return callable(): string
     */
    private function boost(?\Throwable $failure = null): callable
    {
        return function () use ($failure): string {
            $this->ran[] = 'boost';
            if ($failure !== null) {
                throw $failure;
            }
            return 'boost';
        };
    }

    /**
     * Core implementation
     *
     * @return callable(): string
     */
    private function core(): callable
    {
        return function (): string {
            $this->ran[] = 'core';
            return 'core';
        };
    }
}
