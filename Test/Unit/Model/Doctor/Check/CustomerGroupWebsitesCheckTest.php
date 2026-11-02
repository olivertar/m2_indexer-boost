<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Doctor\Check;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Orangecat\IndexerBoost\Model\Doctor\Check\CustomerGroupWebsitesCheck;
use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use Orangecat\IndexerBoost\Test\Unit\Fake\WriteGuardAdapterTrait;
use PHPUnit\Framework\TestCase;

class CustomerGroupWebsitesCheckTest extends TestCase
{
    use WriteGuardAdapterTrait;

    private const GROUPS = ['0' => 'NOT LOGGED IN', '1' => 'General', '2' => 'Wholesale', '3' => 'Retailer'];
    private const WEBSITES = ['1' => 'base', '2' => 'b2b'];

    public function testUnusedPairsArePresentedAsCandidates(): void
    {
        $check = $this->check(
            '1',
            [['customer_group_id' => '3', 'website_id' => '2']],
            [
                ['group_id' => '1', 'website_id' => '1', 'customers' => '900'],
                ['group_id' => '1', 'website_id' => '2', 'customers' => '3'],
                ['group_id' => '2', 'website_id' => '2', 'customers' => '40'],
            ]
        );

        $result = $check->check(new DoctorContext())[0]->toArray();

        $this->assertTrue($check->isHeavy());
        $this->assertSame(
            [
                'severity' => 'info',
                'code' => 'CUSTOMER_GROUP_WEBSITES',
                'message' => '4 customer groups x 2 websites = 8 combinations, 1 excluded. Groups without customers '
                    . 'on a website: Wholesale@base, Retailer@base',
                'recommendation' => 'Price and catalog rule indexes compute every customer group and website '
                    . 'combination. Excluding websites a group never buys on removes those rows. Confirm with the '
                    . 'business first: the group may be assigned by integrations or rules.',
                'fix' => 'Customers > Customer Groups > edit group > Excluded Website(s)',
                'metrics' => [
                    'groups' => 4,
                    'websites' => 2,
                    'combinations' => 8,
                    'excluded' => 1,
                    'candidates' => ['Wholesale@base', 'Retailer@base'],
                ],
            ],
            $result
        );
        $this->assertNoWriteAttempts();
    }

    public function testNoCandidatesIsOk(): void
    {
        $check = $this->check(
            '1',
            [['customer_group_id' => '2', 'website_id' => '1'], ['customer_group_id' => '3', 'website_id' => '1']],
            [
                ['group_id' => '1', 'website_id' => '1', 'customers' => '9'],
                ['group_id' => '1', 'website_id' => '2', 'customers' => '9'],
                ['group_id' => '2', 'website_id' => '2', 'customers' => '9'],
                ['group_id' => '3', 'website_id' => '2', 'customers' => '9'],
            ]
        );

        $result = $check->check(new DoctorContext())[0]->toArray();

        $this->assertSame(
            ['ok', '4 customer groups x 2 websites = 8 combinations, 2 excluded. Every remaining group has customers '
                . 'on its websites'],
            [$result['severity'], $result['message']]
        );
    }

    public function testGlobalAccountSharingDoesNotProposeCandidates(): void
    {
        $check = $this->check('0', [], []);

        $result = $check->check(new DoctorContext())[0]->toArray();

        $this->assertSame(
            ['info', '4 customer groups x 2 websites = 8 combinations, 0 excluded. Customer accounts are shared '
                . 'globally, so customers can buy on any website: no exclusion candidates are proposed'],
            [$result['severity'], $result['message']]
        );
    }

    /**
     * @param string $shareScope customer/account_share/scope (0 global, 1 per website)
     * @param array<int,array<string,string>> $excluded
     * @param array<int,array<string,string>> $customers
     * @return CustomerGroupWebsitesCheck
     */
    private function check(string $shareScope, array $excluded, array $customers): CustomerGroupWebsitesCheck
    {
        $adapter = $this->createWriteGuardAdapter(static fn (string $sql): array => match (true) {
            str_contains($sql, 'pfx_customer_group_excluded_website') => $excluded,
            str_contains($sql, 'pfx_customer_group') => self::GROUPS,
            str_contains($sql, 'pfx_store_website') => self::WEBSITES,
            str_contains($sql, 'pfx_customer_entity') => $customers,
            default => throw new \LogicException($sql),
        });
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap(
            [['customer/account_share/scope', 'default', null, $shareScope]]
        );

        return new CustomerGroupWebsitesCheck(
            new ReadOnlyConnection($this->createResourceConnection($adapter)),
            $scopeConfig
        );
    }
}
