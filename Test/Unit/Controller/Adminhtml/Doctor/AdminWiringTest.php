<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Controller\Adminhtml\Doctor;

use Orangecat\IndexerBoost\Controller\Adminhtml\Doctor\Index;
use PHPUnit\Framework\TestCase;

/**
 * Menu entry, ACL resource, admin route and controller point at each other
 */
class AdminWiringTest extends TestCase
{
    private const ETC = __DIR__ . '/../../../../../etc';

    public function testMenuOpensTheControllerUnderSystemToolsWithItsAclResource(): void
    {
        $item = $this->xpath('adminhtml/menu.xml', '//add[@id="Orangecat_IndexerBoost::doctor"]')[0];
        $route = $this->xpath('adminhtml/routes.xml', '//router[@id="admin"]/route')[0];

        $this->assertSame('Magento_Backend::system_tools', (string)$item['parent']);
        $this->assertSame(Index::ADMIN_RESOURCE, (string)$item['resource']);
        $this->assertSame((string)$route['frontName'] . '/doctor/index', (string)$item['action']);
    }

    public function testAclResourceIsDeclaredUnderSystemTools(): void
    {
        $this->assertCount(1, $this->xpath(
            'acl.xml',
            '//resource[@id="Magento_Backend::system"]/resource[@id="Magento_Backend::tools"]'
            . '/resource[@id="' . Index::ADMIN_RESOURCE . '"]'
        ));
    }

    /**
     * @param string $file relative to etc/
     * @param string $query
     * @return \SimpleXMLElement[]
     */
    private function xpath(string $file, string $query): array
    {
        $xml = simplexml_load_file(self::ETC . '/' . $file);
        $this->assertNotFalse($xml, $file);
        $nodes = $xml->xpath($query) ?: [];
        $this->assertNotEmpty($nodes, $query);
        return $nodes;
    }
}
