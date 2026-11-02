<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Doctor\Check;

use Orangecat\IndexerBoost\Model\Config;
use Orangecat\IndexerBoost\Model\Doctor\CheckInterface;
use Orangecat\IndexerBoost\Model\Doctor\CheckResult;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use Orangecat\IndexerBoost\Model\Doctor\Severity;
use Orangecat\IndexerBoost\Model\Indexer\RewrittenIndexers;

/**
 * Which rewritten indexers run the Indexer Boost implementation and which the core one
 */
class BoostStatusCheck implements CheckInterface
{
    public const CODE = 'BOOST_STATUS';

    /**
     * @param RewrittenIndexers $rewrittenIndexers
     * @param Config $config
     */
    public function __construct(
        private readonly RewrittenIndexers $rewrittenIndexers,
        private readonly Config $config
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
        $enabled = [];
        foreach ($this->rewrittenIndexers->getIds() as $indexerId) {
            $enabled[$indexerId] = $this->config->isIndexerEnabled($indexerId);
        }
        $on = array_keys(array_filter($enabled));
        $core = array_keys(array_diff_key($enabled, array_flip($on)));

        return [
            new CheckResult(
                Severity::Info,
                self::CODE,
                sprintf(
                    'Indexer Boost implementation on: %s; core: %s',
                    $on ? implode(', ', $on) : 'none',
                    $core ? implode(', ', $core) : 'none'
                ),
                'Each rewritten indexer is switched with its own flag. Check BOOST_COMPATIBILITY before turning '
                . 'one on, and compare its output with the core one on a copy of the database first.',
                'bin/magento indexer-boost:toggle <indexer_id> on|off',
                ['enabled' => $enabled]
            ),
        ];
    }
}
