<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Doctor\Check;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Orangecat\IndexerBoost\Model\Doctor\Check\EavIndexerCheck;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use Orangecat\IndexerBoost\Model\Doctor\Source\VendorReferenceScanner;
use PHPUnit\Framework\TestCase;

class EavIndexerCheckTest extends TestCase
{
    private const ROOTS = ['vendor', 'app/code'];
    private const NEEDLES = ['catalog_product_index_eav'];
    private const EXCLUDED = ['/Test/'];

    public function testEnabledWithoutReadersRecommendsDisabling(): void
    {
        $this->assertSame(
            [
                'severity' => 'info',
                'code' => 'EAV_INDEXER',
                'message' => 'EAV indexer is enabled but no installed code outside the indexer references '
                    . 'catalog_product_index_eav (1200 files scanned in vendor, app/code)',
                'recommendation' => 'Layered navigation and search read OpenSearch, not this table, so the '
                    . 'catalog_product_attribute indexer may be doing work nobody uses. The scan is a plain-text '
                    . 'search: it cannot see table names built at runtime or external readers (reports, BI '
                    . 'exports, SQL views). Confirm before disabling; re-enabling needs a full reindex of '
                    . 'catalog_product_attribute.',
                'fix' => 'bin/magento config:set catalog/search/enable_eav_indexer 0',
                'metrics' => ['enabled' => true, 'files_scanned' => 1200, 'readers' => []],
            ],
            $this->findings('1', [])[0]
        );
    }

    public function testEnabledWithReadersIsOk(): void
    {
        $result = $this->findings('1', [
            'vendor/acme/nav/Model/Filter.php' => ['catalog_product_index_eav'],
            'vendor/acme/nav/etc/di.xml' => ['catalog_product_index_eav'],
        ])[0];

        $this->assertSame('ok', $result['severity']);
        $this->assertSame(
            'EAV indexer is enabled and referenced by 2 file(s): vendor/acme/nav/Model/Filter.php, '
            . 'vendor/acme/nav/etc/di.xml',
            $result['message']
        );
    }

    public function testDisabledWithReadersIsWarnBecauseTheyReadStaleData(): void
    {
        $readers = [];
        foreach (range(1, 7) as $i) {
            $readers['vendor/acme/m' . $i . '/Model/R.php'] = ['catalog_product_index_eav'];
        }

        $result = $this->findings('0', $readers)[0];

        $this->assertSame(
            [
                'warn',
                'EAV indexer is disabled but 7 file(s) reference catalog_product_index_eav: '
                    . 'vendor/acme/m1/Model/R.php, vendor/acme/m2/Model/R.php, vendor/acme/m3/Model/R.php, '
                    . 'vendor/acme/m4/Model/R.php, vendor/acme/m5/Model/R.php and 2 more',
                'bin/magento config:set catalog/search/enable_eav_indexer 1 && '
                    . 'bin/magento indexer:reindex catalog_product_attribute',
            ],
            [$result['severity'], $result['message'], $result['fix']]
        );
        $this->assertCount(7, $result['metrics']['readers']);
    }

    public function testDisabledWithoutReadersIsOk(): void
    {
        $result = $this->findings(null, [])[0];

        $this->assertSame(
            ['ok', 'EAV indexer is disabled and no installed code outside the indexer references it'],
            [$result['severity'], $result['message']]
        );
    }

    /**
     * @param string|null $flag catalog/search/enable_eav_indexer
     * @param array<string,string[]> $matches
     * @return array<int,array<string,mixed>>
     */
    private function findings(?string $flag, array $matches): array
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap(
            [['catalog/search/enable_eav_indexer', 'default', null, $flag]]
        );
        $scanner = $this->createMock(VendorReferenceScanner::class);
        $scanner->expects($this->once())
            ->method('scan')
            ->with(self::ROOTS, self::NEEDLES, self::EXCLUDED)
            ->willReturn(['files' => 1200, 'matches' => $matches]);
        $check = new EavIndexerCheck($scopeConfig, $scanner, self::ROOTS, self::NEEDLES, self::EXCLUDED);

        $this->assertTrue($check->isHeavy());
        return array_map(static fn ($result): array => $result->toArray(), $check->check(new DoctorContext()));
    }
}
