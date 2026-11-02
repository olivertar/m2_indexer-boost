<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Controller\Adminhtml\Doctor;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\View\Page\Config;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\PageFactory;
use Orangecat\IndexerBoost\Controller\Adminhtml\Doctor\Index;
use PHPUnit\Framework\TestCase;

class IndexTest extends TestCase
{
    public function testReadOnlyPageUnderItsOwnAclResource(): void
    {
        $this->assertSame('Orangecat_IndexerBoost::doctor', Index::ADMIN_RESOURCE);
        $this->assertTrue(is_subclass_of(Index::class, HttpGetActionInterface::class));
        $this->assertFalse(is_subclass_of(Index::class, HttpPostActionInterface::class));
    }

    public function testRendersThePageWithMenuAndTitle(): void
    {
        $title = $this->createMock(Title::class);
        $title->expects($this->once())->method('prepend')->with('Indexer Boost');
        $config = $this->createStub(Config::class);
        $config->method('getTitle')->willReturn($title);
        $page = $this->createMock(Page::class);
        $page->expects($this->once())
            ->method('setActiveMenu')
            ->with('Orangecat_IndexerBoost::doctor')
            ->willReturnSelf();
        $page->method('getConfig')->willReturn($config);
        $pageFactory = $this->createStub(PageFactory::class);
        $pageFactory->method('create')->willReturn($page);

        $controller = new Index($this->createStub(Context::class), $pageFactory);

        $this->assertSame($page, $controller->execute());
    }
}
