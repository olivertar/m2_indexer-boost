<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Doctor\Report;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Orangecat\IndexerBoost\Model\Doctor\CheckResult;
use Orangecat\IndexerBoost\Model\Doctor\Severity;
use Orangecat\IndexerBoost\Model\Monitor\Clock;

/**
 * Keeps the findings of the last full doctor run in var/, for the admin page.
 *
 * Heavy checks only run from the CLI; the admin page shows their last results from this file.
 */
class LastReportStorage
{
    /**
     * Path relative to var/
     */
    public const PATH = 'indexer-boost/doctor-last.json';

    private const FORMAT_VERSION = 1;

    private const DATE_FORMAT = 'Y-m-d\TH:i:s\Z';

    /**
     * @param Filesystem $filesystem
     * @param Clock $clock
     */
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly Clock $clock
    ) {
    }

    /**
     * Save the findings of a doctor run, replacing the previous report
     *
     * @param CheckResult[] $results
     * @return void
     * @throws \Magento\Framework\Exception\FileSystemException
     * @throws \JsonException
     */
    public function save(array $results): void
    {
        $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR)->writeFile(
            self::PATH,
            json_encode(
                [
                    'version' => self::FORMAT_VERSION,
                    'generated_at' => $this->clock->now()->format(self::DATE_FORMAT),
                    'findings' => array_map(static fn (CheckResult $result): array => $result->toArray(), $results),
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            )
        );
    }

    /**
     * Last saved report
     *
     * @return LastReport|null null when no report was saved or the file cannot be read back
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    public function load(): ?LastReport
    {
        $directory = $this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR);
        if (!$directory->isFile(self::PATH)) {
            return null;
        }
        try {
            return $this->parse(json_decode($directory->readFile(self::PATH), true, 512, JSON_THROW_ON_ERROR));
        } catch (\JsonException | \UnexpectedValueException | \ValueError | \TypeError) {
            return null;
        }
    }

    /**
     * Rebuild a report from its decoded JSON
     *
     * @param mixed $data
     * @return LastReport
     * @throws \UnexpectedValueException on a malformed report
     */
    private function parse(mixed $data): LastReport
    {
        if (!is_array($data) || ($data['version'] ?? null) !== self::FORMAT_VERSION
            || !is_string($data['generated_at'] ?? null) || !is_array($data['findings'] ?? null)
        ) {
            throw new \UnexpectedValueException('Unknown report format');
        }
        $generatedAt = \DateTimeImmutable::createFromFormat(
            '!' . self::DATE_FORMAT,
            $data['generated_at'],
            new \DateTimeZone('UTC')
        );
        if ($generatedAt === false) {
            throw new \UnexpectedValueException('Invalid report date');
        }

        $results = [];
        foreach ($data['findings'] as $finding) {
            if (!is_array($finding)) {
                throw new \UnexpectedValueException('Invalid finding');
            }
            // Strict types: a missing or mistyped field raises a TypeError
            $results[] = new CheckResult(
                Severity::from($finding['severity'] ?? ''),
                $finding['code'] ?? null,
                $finding['message'] ?? null,
                $finding['recommendation'] ?? null,
                $finding['fix'] ?? null,
                $finding['metrics'] ?? null
            );
        }
        return new LastReport($generatedAt, $results);
    }
}
