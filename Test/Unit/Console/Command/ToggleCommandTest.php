<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Console\Command;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Orangecat\IndexerBoost\Console\Command\ToggleCommand;
use Orangecat\IndexerBoost\Model\Indexer\RewrittenIndexers;
use Orangecat\IndexerBoost\Model\Indexer\Toggle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ToggleCommandTest extends TestCase
{
    public function testRewrittenIndexerIsSwitchedOnAndTheConfigCacheCleaned(): void
    {
        $writer = $this->createMock(WriterInterface::class);
        $writer->expects($this->once())->method('save')->with('indexer_boost/catalog_category_product/enabled', '1');
        $cacheTypeList = $this->createMock(TypeListInterface::class);
        $cacheTypeList->expects($this->once())->method('cleanType')->with('config');
        $tester = new CommandTester($this->command($writer, $cacheTypeList));

        $exitCode = $tester->execute(['indexer' => 'catalog_category_product', 'state' => 'on']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString(
            'catalog_category_product: Indexer Boost implementation on',
            $tester->getDisplay()
        );
    }

    public function testOffGoesBackToTheCore(): void
    {
        $writer = $this->createMock(WriterInterface::class);
        $writer->expects($this->once())->method('save')->with('indexer_boost/catalog_category_product/enabled', '0');
        $tester = new CommandTester($this->command($writer, $this->createStub(TypeListInterface::class)));

        $exitCode = $tester->execute(['indexer' => 'catalog_category_product', 'state' => 'off']);

        $this->assertSame(Command::SUCCESS, $exitCode);
    }

    /**
     * @param array<string,string> $input
     */
    #[DataProvider('invalidProvider')]
    public function testNothingIsWrittenOnInvalidInput(array $input, string $error): void
    {
        $writer = $this->createMock(WriterInterface::class);
        $writer->expects($this->never())->method('save');
        $tester = new CommandTester($this->command($writer, $this->createStub(TypeListInterface::class)));

        $exitCode = $tester->execute($input);

        $this->assertSame(Command::INVALID, $exitCode);
        $this->assertStringContainsString($error, $tester->getDisplay());
    }

    /**
     * @return array<string,array{array<string,string>,string}>
     */
    public static function invalidProvider(): array
    {
        return [
            'not rewritten' => [
                ['indexer' => 'catalog_product_price', 'state' => 'on'],
                'catalog_product_price has no Indexer Boost implementation. Rewritten: catalog_category_product',
            ],
            'state' => [['indexer' => 'catalog_category_product', 'state' => 'yes'], 'State must be on or off'],
        ];
    }

    public function testNothingRewrittenYetIsSaid(): void
    {
        $command = new ToggleCommand(new Toggle(
            $this->createStub(WriterInterface::class),
            $this->createStub(TypeListInterface::class),
            new RewrittenIndexers([])
        ));
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['indexer' => 'catalog_category_product', 'state' => 'on']);

        $this->assertSame(Command::INVALID, $exitCode);
        $this->assertStringContainsString('Rewritten: none yet', $tester->getDisplay());
    }

    /**
     * Command over fakes, with catalog_category_product as the only rewritten indexer
     *
     * @param WriterInterface $writer
     * @param TypeListInterface $cacheTypeList
     * @return ToggleCommand
     */
    private function command(WriterInterface $writer, TypeListInterface $cacheTypeList): ToggleCommand
    {
        return new ToggleCommand(
            new Toggle($writer, $cacheTypeList, new RewrittenIndexers(['catalog_category_product']))
        );
    }
}
