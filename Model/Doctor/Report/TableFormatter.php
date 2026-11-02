<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Doctor\Report;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Renders doctor findings as a console table
 */
class TableFormatter implements FormatterInterface
{
    private const TEXT_COLUMN_MAX_WIDTH = 60;

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
        $output = new BufferedOutput();
        $table = new Table($output);
        $table->setHeaders(['Severity', 'Code', 'Message', 'Recommendation / Fix']);
        $table->setColumnMaxWidth(2, self::TEXT_COLUMN_MAX_WIDTH);
        $table->setColumnMaxWidth(3, self::TEXT_COLUMN_MAX_WIDTH);
        foreach ($results as $result) {
            $row = $result->toArray();
            $details = array_filter(
                [$row['recommendation'], $row['fix']],
                static fn (string $value): bool => $value !== ''
            );
            $table->addRow([strtoupper($row['severity']), $row['code'], $row['message'], implode("\n", $details)]);
        }
        $table->render();

        $output->writeln('Summary: ' . $this->summary->describe($results));

        return $output->fetch();
    }
}
