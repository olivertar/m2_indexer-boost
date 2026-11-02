<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Doctor\System;

use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem\Driver\File;

/**
 * Read-only facts about the PHP process and host, isolated so checks can be tested
 */
class SystemInfo
{
    private const CPUINFO = '/proc/cpuinfo';

    /**
     * @param File $file
     */
    public function __construct(
        private readonly File $file
    ) {
    }

    /**
     * Whether process forking (used by parallel reindex) is available
     *
     * @return bool
     */
    public function isPcntlAvailable(): bool
    {
        return function_exists('pcntl_fork');
    }

    /**
     * Number of CPU cores visible to the process, null when unknown
     *
     * @return int|null
     */
    public function getCpuCores(): ?int
    {
        try {
            if (!$this->file->isReadable(self::CPUINFO)) {
                return null;
            }
            $count = preg_match_all('/^processor\s*:/m', $this->file->fileGetContents(self::CPUINFO));
        } catch (FileSystemException) {
            return null;
        }
        return $count ?: null;
    }
}
