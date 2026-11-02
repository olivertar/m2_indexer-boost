<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Doctor\Check;

use Magento\Cron\Model\ConfigInterface;
use Orangecat\IndexerBoost\Model\Doctor\Catalog\CatalogSizeProvider;
use Orangecat\IndexerBoost\Model\Doctor\CheckInterface;
use Orangecat\IndexerBoost\Model\Doctor\CheckResult;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use Orangecat\IndexerBoost\Model\Doctor\Severity;

/**
 * Presence and schedule of catalogrule_apply_all, which invalidates catalogrule_product on every run
 */
class CatalogRuleCronCheck implements CheckInterface
{
    public const CODE = 'CATALOG_RULE_CRON';
    private const JOB = 'catalogrule_apply_all';

    /**
     * @param ConfigInterface $cronConfig
     * @param CatalogSizeProvider $catalogSizeProvider
     * @param int $warnFromProducts
     */
    public function __construct(
        private readonly ConfigInterface $cronConfig,
        private readonly CatalogSizeProvider $catalogSizeProvider,
        private readonly int $warnFromProducts = 5000
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
        $group = '';
        $schedule = '';
        foreach ($this->cronConfig->getJobs() as $groupId => $jobs) {
            if (isset($jobs[self::JOB])) {
                $group = (string)$groupId;
                $schedule = (string)($jobs[self::JOB]['schedule'] ?? '');
            }
        }
        if ($group === '') {
            return [new CheckResult(Severity::Ok, self::CODE, self::JOB . ' is not scheduled')];
        }

        $size = $this->catalogSizeProvider->get($context);
        $activeRules = (int)$size->toArray()['active_catalog_rules'];
        if ($activeRules === 0) {
            return [
                new CheckResult(
                    Severity::Ok,
                    self::CODE,
                    sprintf(
                        '%s is scheduled at "%s" but no catalog rule is active: it does nothing',
                        self::JOB,
                        $schedule
                    )
                ),
            ];
        }

        return [
            new CheckResult(
                $size->getProductCount() >= $this->warnFromProducts ? Severity::Warn : Severity::Info,
                self::CODE,
                sprintf(
                    '%s runs at "%s" (group %s) and invalidates catalogrule_product while %d catalog rule(s) are '
                    . 'active: a full catalog rule reindex follows every run',
                    self::JOB,
                    $schedule,
                    $group,
                    $activeRules
                ),
                'The job exists so rules with date ranges start and stop applying. Make sure it runs in a '
                . 'low-traffic window and that the full reindex that follows fits in it.',
                'Move it if needed: override the ' . self::JOB . ' <schedule> in a custom module etc/crontab.xml',
                ['schedule' => $schedule, 'group' => $group, 'active_rules' => $activeRules]
            ),
        ];
    }
}
