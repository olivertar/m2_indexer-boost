<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Doctor\Source;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File;
use Orangecat\IndexerBoost\Model\Doctor\Source\VendorReferenceScanner;
use PHPUnit\Framework\TestCase;

class VendorReferenceScannerTest extends TestCase
{
    private const NEEDLES = ['catalog_product_index_eav', 'Magento\\Catalog\\Model\\Layer\\Filter\\Attribute'];

    /**
     * Temporary Magento root of the test
     *
     * @var string
     */
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/indexer-boost-scan-' . bin2hex(random_bytes(4));
        $this->write('vendor/acme/module-nav/Model/Filter.php', '<?php $t = "catalog_product_index_eav_decimal";');
        $this->write(
            'vendor/acme/module-nav/etc/di.xml',
            '<type name="Magento\\Catalog\\Model\\Layer\\Filter\\Attribute"/><x>catalog_product_index_eav</x>'
        );
        $this->write('vendor/acme/module-nav/Model/Clean.php', '<?php // nothing here');
        $this->write('vendor/acme/module-nav/README.md', 'catalog_product_index_eav');
        $this->write('vendor/acme/module-nav/Test/Unit/FilterTest.php', '<?php "catalog_product_index_eav";');
        $this->write('vendor/magento/module-catalog/Indexer/Eav/Source.php', '<?php "catalog_product_index_eav";');
        $this->write('vendor/magento/module-catalog/etc/db_schema.xml', '<table name="catalog_product_index_eav"/>');
        $this->write('app/code/Vendor/Layer/view/frontend/templates/nav.phtml', '<?= "catalog_product_index_eav" ?>');
    }

    protected function tearDown(): void
    {
        (new File())->deleteDirectory($this->root);
    }

    public function testFindsNeedlesInPhpXmlAndPhtmlOutsideExcludedPaths(): void
    {
        $result = $this->scanner()->scan(
            ['vendor', 'app/code', 'missing/root'],
            self::NEEDLES,
            ['/Test/', 'vendor/magento/module-catalog/Indexer/Eav/', 'vendor/magento/module-catalog/etc/db_schema.xml']
        );

        $this->assertSame(
            [
                'files' => 4,
                'matches' => [
                    'app/code/Vendor/Layer/view/frontend/templates/nav.phtml' => ['catalog_product_index_eav'],
                    'vendor/acme/module-nav/Model/Filter.php' => ['catalog_product_index_eav'],
                    'vendor/acme/module-nav/etc/di.xml' => self::NEEDLES,
                ],
            ],
            $result
        );
    }

    public function testWithoutExclusionsCoreFilesAreReported(): void
    {
        $result = $this->scanner()->scan(['vendor'], ['catalog_product_index_eav'], []);

        $this->assertSame(
            [
                'vendor/acme/module-nav/Model/Filter.php',
                'vendor/acme/module-nav/Test/Unit/FilterTest.php',
                'vendor/acme/module-nav/etc/di.xml',
                'vendor/magento/module-catalog/Indexer/Eav/Source.php',
                'vendor/magento/module-catalog/etc/db_schema.xml',
            ],
            array_keys($result['matches'])
        );
        $this->assertSame(6, $result['files']);
    }

    /**
     * @return VendorReferenceScanner
     */
    private function scanner(): VendorReferenceScanner
    {
        $directoryList = $this->createStub(DirectoryList::class);
        $directoryList->method('getRoot')->willReturn($this->root);
        return new VendorReferenceScanner(new File(), $directoryList);
    }

    /**
     * @param string $path
     * @param string $content
     * @return void
     */
    private function write(string $path, string $content): void
    {
        $driver = new File();
        $driver->createDirectory(dirname($this->root . '/' . $path));
        $driver->filePutContents($this->root . '/' . $path, $content);
    }
}
