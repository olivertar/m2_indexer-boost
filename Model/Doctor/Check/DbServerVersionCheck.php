<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Doctor\Check;

use Magento\Framework\App\ProductMetadataInterface;
use Orangecat\IndexerBoost\Model\Doctor\CheckInterface;
use Orangecat\IndexerBoost\Model\Doctor\CheckResult;
use Orangecat\IndexerBoost\Model\Doctor\Db\DbServerProvider;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use Orangecat\IndexerBoost\Model\Doctor\Severity;

/**
 * Database engine and version against the support matrix of the installed Magento version
 */
class DbServerVersionCheck implements CheckInterface
{
    public const CODE = 'DB_SERVER_VERSION';

    /**
     * @param DbServerProvider $dbServerProvider
     * @param ProductMetadataInterface $productMetadata
     * @param array<string,array<string,string[]>> $supportedSeries Magento x.y.z => engine => supported series
     */
    public function __construct(
        private readonly DbServerProvider $dbServerProvider,
        private readonly ProductMetadataInterface $productMetadata,
        private readonly array $supportedSeries = []
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getCode(): string
    {
        return self::CODE;
    }

    /**
     * @inheritdoc
     */
    public function isHeavy(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function check(DoctorContext $context): array
    {
        $server = $this->dbServerProvider->get($context);
        $magento = preg_match('/^\d+\.\d+\.\d+/', (string)$this->productMetadata->getVersion(), $matches)
            ? $matches[0]
            : (string)$this->productMetadata->getVersion();
        $engine = strtolower($server->getEngine());
        $serverName = $server->getEngine() . ' ' . $server->getSeries();

        if (!isset($this->supportedSeries[$magento])) {
            return [
                new CheckResult(
                    Severity::Info,
                    self::CODE,
                    sprintf('No database support matrix for Magento %s (server: %s)', $magento, $serverName),
                    'Add the supported database versions for this Magento release to the supportedSeries argument '
                    . 'of ' . self::class . ' in di.xml.'
                ),
            ];
        }

        $supported = $this->supportedSeries[$magento][$engine] ?? [];
        if (in_array($server->getSeries(), $supported, true)) {
            return [
                new CheckResult(
                    Severity::Ok,
                    self::CODE,
                    sprintf('%s is supported by Magento %s', $serverName, $magento)
                ),
            ];
        }

        $supportedNames = implode(
            ', ',
            array_map(static fn (string $series): string => $server->getEngine() . ' ' . $series, $supported)
        );
        return [
            new CheckResult(
                Severity::Warn,
                self::CODE,
                sprintf(
                    '%s (%s) is not in the supported list for Magento %s (%s)',
                    $serverName,
                    $server->getVersion(),
                    $magento,
                    $supportedNames
                ),
                'Unsupported database versions are not covered by Magento QA and may behave differently in indexer '
                . 'queries (optimizer, temporary tables). The support matrix lives in di.xml and can be updated '
                . 'without code changes.',
                sprintf('Plan the upgrade to %s and test it in staging with the benchmark', $supportedNames),
                ['magento' => $magento, 'engine' => $engine, 'series' => $server->getSeries()]
            ),
        ];
    }
}
