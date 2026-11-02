<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Doctor\Report;

/**
 * Renders doctor findings as a Markdown report
 */
class MarkdownFormatter implements FormatterInterface
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
        $lines = [
            '# Indexer Boost doctor report',
            '',
            '**Summary:** ' . $this->summary->describe($results),
            '',
            '| Severity | Code | Message | Recommendation | Fix |',
            '|---|---|---|---|---|',
        ];
        foreach ($results as $result) {
            $row = $result->toArray();
            $lines[] = sprintf(
                '| %s | %s | %s | %s | %s |',
                $row['severity'],
                $this->escape($row['code']),
                $this->escape($row['message']),
                $this->escape($row['recommendation']),
                $row['fix'] === '' ? '' : '`' . $this->escape($row['fix']) . '`'
            );
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Escape a value for a Markdown table cell
     *
     * @param string $value
     * @return string
     */
    private function escape(string $value): string
    {
        return str_replace(['|', "\r\n", "\n"], ['\|', '<br>', '<br>'], $value);
    }
}
