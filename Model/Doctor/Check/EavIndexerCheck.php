<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Doctor\Check;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Orangecat\IndexerBoost\Model\Doctor\CheckInterface;
use Orangecat\IndexerBoost\Model\Doctor\CheckResult;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use Orangecat\IndexerBoost\Model\Doctor\Severity;
use Orangecat\IndexerBoost\Model\Doctor\Source\VendorReferenceScanner;

/**
 * Whether the product EAV indexer is enabled and whether installed code outside the indexer reads its tables
 */
class EavIndexerCheck implements CheckInterface
{
    public const CODE = 'EAV_INDEXER';

    /**
     * Same path as \Magento\Catalog\Model\Indexer\Product\Eav\AbstractAction::ENABLE_EAV_INDEXER
     */
    private const XML_PATH_ENABLED = 'catalog/search/enable_eav_indexer';

    /**
     * Readers listed in a message before "and N more"
     */
    private const LISTED_READERS = 5;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param VendorReferenceScanner $scanner
     * @param string[] $roots
     * @param string[] $needles
     * @param string[] $excludedPaths
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly VendorReferenceScanner $scanner,
        private readonly array $roots = [],
        private readonly array $needles = [],
        private readonly array $excludedPaths = []
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
        return true;
    }

    /**
     * @inheritdoc
     */
    public function check(DoctorContext $context): array
    {
        // Read like the core does: any falsy value disables the indexer
        $enabled = (bool)$this->scopeConfig->getValue(self::XML_PATH_ENABLED);
        $scan = $this->scanner->scan(
            array_values($this->roots),
            array_values($this->needles),
            array_values($this->excludedPaths)
        );
        $readers = array_keys($scan['matches']);
        $metrics = ['enabled' => $enabled, 'files_scanned' => $scan['files'], 'readers' => $scan['matches']];

        if ($enabled && !$readers) {
            return [
                new CheckResult(
                    Severity::Info,
                    self::CODE,
                    sprintf(
                        'EAV indexer is enabled but no installed code outside the indexer references '
                        . 'catalog_product_index_eav (%d files scanned in %s)',
                        $scan['files'],
                        implode(', ', $this->roots)
                    ),
                    'Layered navigation and search read OpenSearch, not this table, so the catalog_product_attribute '
                    . 'indexer may be doing work nobody uses. The scan is a plain-text search: it cannot see table '
                    . 'names built at runtime or external readers (reports, BI exports, SQL views). Confirm before '
                    . 'disabling; re-enabling needs a full reindex of catalog_product_attribute.',
                    'bin/magento config:set ' . self::XML_PATH_ENABLED . ' 0',
                    $metrics
                ),
            ];
        }

        if ($enabled) {
            return [
                new CheckResult(
                    Severity::Ok,
                    self::CODE,
                    sprintf(
                        'EAV indexer is enabled and referenced by %d file(s): %s',
                        count($readers),
                        $this->describe($readers)
                    ),
                    '',
                    '',
                    $metrics
                ),
            ];
        }

        if ($readers) {
            return [
                new CheckResult(
                    Severity::Warn,
                    self::CODE,
                    sprintf(
                        'EAV indexer is disabled but %d file(s) reference catalog_product_index_eav: %s',
                        count($readers),
                        $this->describe($readers)
                    ),
                    'A disabled EAV indexer skips every reindex, so the table keeps its last content and these '
                    . 'modules read stale data. Check whether they really query it; if they do, enable the indexer.',
                    'bin/magento config:set ' . self::XML_PATH_ENABLED . ' 1 && '
                    . 'bin/magento indexer:reindex catalog_product_attribute',
                    $metrics
                ),
            ];
        }

        return [
            new CheckResult(
                Severity::Ok,
                self::CODE,
                'EAV indexer is disabled and no installed code outside the indexer references it',
                '',
                '',
                $metrics
            ),
        ];
    }

    /**
     * List the first readers, e.g. "a.php, b.php and 3 more"
     *
     * @param string[] $readers
     * @return string
     */
    private function describe(array $readers): string
    {
        $listed = implode(', ', array_slice($readers, 0, self::LISTED_READERS));
        $more = count($readers) - self::LISTED_READERS;
        return $more > 0 ? sprintf('%s and %d more', $listed, $more) : $listed;
    }
}
