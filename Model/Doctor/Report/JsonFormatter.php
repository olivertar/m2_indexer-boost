<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Doctor\Report;

use Orangecat\IndexerBoost\Model\Doctor\CheckResult;

/**
 * Renders doctor findings as JSON, for CI and tooling
 */
class JsonFormatter implements FormatterInterface
{
    /**
     * @param Summary $summary
     */
    public function __construct(
        private readonly Summary $summary
    ) {
    }

    /**
     * @inheritdoc
     */
    public function format(array $results): string
    {
        $summary = $this->summary->count($results);

        return json_encode(
            [
                'summary' => $summary,
                'findings' => array_map(static fn (CheckResult $result): array => $result->toArray(), $results),
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }
}
