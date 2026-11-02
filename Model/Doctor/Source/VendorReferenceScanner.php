<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Doctor\Source;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem\Driver\File;

/**
 * Plain-text search for references (table or class names) in installed source code.
 *
 * Best effort: names built at runtime are not found. Excluded paths are pruned before descending.
 */
class VendorReferenceScanner
{
    /**
     * @param File $driver
     * @param DirectoryList $directoryList
     */
    public function __construct(
        private readonly File $driver,
        private readonly DirectoryList $directoryList
    ) {
    }

    /**
     * Files under $roots that contain any of $needles
     *
     * @param string[] $roots directories relative to the Magento root; missing ones are skipped
     * @param string[] $needles
     * @param string[] $excludedPaths relative path fragments, e.g. "/Test/" or "vendor/acme/module-x/Model/Foo.php"
     * @param string[] $extensions
     * @return array{files:int,matches:array<string,string[]>} files scanned and needles found per relative path
     * @throws FileSystemException
     */
    public function scan(
        array $roots,
        array $needles,
        array $excludedPaths,
        array $extensions = ['php', 'phtml', 'xml']
    ): array {
        $base = rtrim($this->directoryList->getRoot(), '/') . '/';
        $result = ['files' => 0, 'matches' => []];
        foreach ($roots as $root) {
            if ($this->driver->isDirectory($base . $root)) {
                $this->scanDirectory($base, trim($root, '/'), $needles, $excludedPaths, $extensions, $result);
            }
        }
        ksort($result['matches'], SORT_STRING);
        return $result;
    }

    /**
     * Recursively scan one directory
     *
     * @param string $base
     * @param string $relative
     * @param string[] $needles
     * @param string[] $excludedPaths
     * @param string[] $extensions
     * @param array{files:int,matches:array<string,string[]>} $result
     * @return void
     * @throws FileSystemException
     */
    private function scanDirectory(
        string $base,
        string $relative,
        array $needles,
        array $excludedPaths,
        array $extensions,
        array &$result
    ): void {
        foreach ($this->driver->readDirectory($base . $relative) as $path) {
            $child = substr($path, strlen($base));
            if ($this->driver->isDirectory($path)) {
                if (!$this->isExcluded($child . '/', $excludedPaths)) {
                    $this->scanDirectory($base, $child, $needles, $excludedPaths, $extensions, $result);
                }
                continue;
            }
            $extension = preg_match('~\.([^./]+)$~', $child, $match) ? $match[1] : '';
            if (!in_array($extension, $extensions, true) || $this->isExcluded($child, $excludedPaths)) {
                continue;
            }
            $result['files']++;
            $content = $this->driver->fileGetContents($path);
            $found = array_values(array_filter(
                $needles,
                static fn (string $needle): bool => str_contains($content, $needle)
            ));
            if ($found) {
                $result['matches'][$child] = $found;
            }
        }
    }

    /**
     * Whether a relative path contains any excluded fragment
     *
     * @param string $path
     * @param string[] $excludedPaths
     * @return bool
     */
    private function isExcluded(string $path, array $excludedPaths): bool
    {
        foreach ($excludedPaths as $excluded) {
            if (str_contains($path, $excluded)) {
                return true;
            }
        }
        return false;
    }
}
