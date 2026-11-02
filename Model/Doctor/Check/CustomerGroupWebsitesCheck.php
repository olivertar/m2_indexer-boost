<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Doctor\Check;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Orangecat\IndexerBoost\Model\Doctor\CheckInterface;
use Orangecat\IndexerBoost\Model\Doctor\CheckResult;
use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use Orangecat\IndexerBoost\Model\Doctor\Severity;

/**
 * Customer group x website matrix used by price and catalog rule indexes, and pairs that could be excluded
 */
class CustomerGroupWebsitesCheck implements CheckInterface
{
    public const CODE = 'CUSTOMER_GROUP_WEBSITES';
    private const NOT_LOGGED_IN_GROUP = 0;
    private const XML_PATH_ACCOUNT_SHARE_SCOPE = 'customer/account_share/scope';

    /**
     * @param ReadOnlyConnection $connection
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ReadOnlyConnection $connection,
        private readonly ScopeConfigInterface $scopeConfig
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
     * CLI only: groups customer_entity, which can hold millions of rows
     *
     * @return bool
     */
    public function isHeavy(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function check(DoctorContext $context): array
    {
        $groups = $this->connection->fetchPairs(sprintf(
            'SELECT customer_group_id, customer_group_code FROM %s',
            $this->connection->getTableName('customer_group')
        ));
        $websites = $this->connection->fetchPairs(sprintf(
            'SELECT website_id, code FROM %s WHERE website_id > 0',
            $this->connection->getTableName('store_website')
        ));
        $excluded = [];
        foreach ($this->connection->fetchAll(sprintf(
            'SELECT customer_group_id, website_id FROM %s',
            $this->connection->getTableName('customer_group_excluded_website')
        )) as $row) {
            $excluded[$row['customer_group_id'] . '-' . $row['website_id']] = true;
        }

        $summary = sprintf(
            '%d customer groups x %d websites = %d combinations, %d excluded.',
            count($groups),
            count($websites),
            count($groups) * count($websites),
            count($excluded)
        );

        $isGlobalSharing = (string)$this->scopeConfig->getValue(
            self::XML_PATH_ACCOUNT_SHARE_SCOPE,
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            null
        ) === '0';
        if ($isGlobalSharing) {
            return [
                new CheckResult(
                    Severity::Info,
                    self::CODE,
                    $summary . ' Customer accounts are shared globally, so customers can buy on any website: '
                    . 'no exclusion candidates are proposed'
                ),
            ];
        }

        $withCustomers = [];
        foreach ($this->connection->fetchAll(sprintf(
            'SELECT group_id, website_id, COUNT(*) AS customers FROM %s GROUP BY group_id, website_id',
            $this->connection->getTableName('customer_entity')
        )) as $row) {
            $withCustomers[$row['group_id'] . '-' . $row['website_id']] = true;
        }

        $candidates = [];
        foreach ($groups as $groupId => $groupCode) {
            if ((int)$groupId === self::NOT_LOGGED_IN_GROUP) {
                continue;
            }
            foreach ($websites as $websiteId => $websiteCode) {
                $pair = $groupId . '-' . $websiteId;
                if (!isset($excluded[$pair]) && !isset($withCustomers[$pair])) {
                    $candidates[] = $groupCode . '@' . $websiteCode;
                }
            }
        }

        if (!$candidates) {
            return [
                new CheckResult(
                    Severity::Ok,
                    self::CODE,
                    $summary . ' Every remaining group has customers on its websites'
                ),
            ];
        }

        return [
            new CheckResult(
                Severity::Info,
                self::CODE,
                $summary . ' Groups without customers on a website: ' . implode(', ', $candidates),
                'Price and catalog rule indexes compute every customer group and website combination. Excluding '
                . 'websites a group never buys on removes those rows. Confirm with the business first: the group '
                . 'may be assigned by integrations or rules.',
                'Customers > Customer Groups > edit group > Excluded Website(s)',
                [
                    'groups' => count($groups),
                    'websites' => count($websites),
                    'combinations' => count($groups) * count($websites),
                    'excluded' => count($excluded),
                    'candidates' => $candidates,
                ]
            ),
        ];
    }
}
