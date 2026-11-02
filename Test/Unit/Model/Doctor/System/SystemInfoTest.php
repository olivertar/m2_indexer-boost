<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Doctor\System;

use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem\Driver\File;
use Orangecat\IndexerBoost\Model\Doctor\System\SystemInfo;
use PHPUnit\Framework\TestCase;

class SystemInfoTest extends TestCase
{
    public function testCpuCoresAreCountedFromProcCpuinfo(): void
    {
        $file = $this->createStub(File::class);
        $file->method('isReadable')->willReturn(true);
        $file->method('fileGetContents')->willReturn(
            "processor\t: 0\nmodel name\t: X\n\nprocessor\t: 1\nmodel name\t: X\n\nprocessor\t: 2\n"
        );

        $this->assertSame(3, (new SystemInfo($file))->getCpuCores());
    }

    public function testCpuCoresAreNullWhenCpuinfoIsNotReadable(): void
    {
        $file = $this->createStub(File::class);
        $file->method('isReadable')->willThrowException(new FileSystemException(__('denied')));

        $this->assertNull((new SystemInfo($file))->getCpuCores());
    }
}
