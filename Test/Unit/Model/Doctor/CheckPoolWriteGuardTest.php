<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Doctor;

use Magento\Cron\Model\ConfigInterface as CronConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Indexer\ConfigInterface as IndexerConfig;
use Orangecat\IndexerBoost\Model\Config;
use Orangecat\IndexerBoost\Model\Doctor\CheckInterface;
use Orangecat\IndexerBoost\Model\Doctor\CheckResult;
use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;
use Orangecat\IndexerBoost\Model\Doctor\Db\WriteNotAllowedException;
use Orangecat\IndexerBoost\Model\Doctor\Doctor;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use Orangecat\IndexerBoost\Test\Unit\Fake\WriteGuardAdapterTrait;
use Orangecat\IndexerBoost\Test\Unit\Model\Doctor\Fixture\DirectAdapterCheck;
use Orangecat\IndexerBoost\Test\Unit\Model\Doctor\Fixture\SwallowedWriteCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs every check of the real di.xml pool against a write-rejecting database and filesystem.
 *
 * Checks are built by reflection with their di.xml arguments; Magento dependencies are stubs, so each
 * check runs to completion against an empty database. Data-dependent branches are covered by each check's
 * own test.
 */
class CheckPoolWriteGuardTest extends TestCase
{
    use WriteGuardAdapterTrait;

    private const MODULE_DIR = __DIR__ . '/../../../..';
    private const XSI = 'http://www.w3.org/2001/XMLSchema-instance';

    /**
     * Every check code of SPEC section 4.2, the phase C ones (BOOST_*, SPEC 6.2.9) and REINDEX_CHURN
     */
    private const SPEC_CODES = [
        'APPLICATION_LOCK', 'ATTRIBUTE_FLAGS', 'BATCH_SIZE', 'BOOST_COMPATIBILITY', 'BOOST_STATUS',
        'CATALOG_RULE_CRON', 'CATALOG_RULES', 'CATALOG_SIZE',
        'CRON_INDEXER', 'CUSTOMER_GROUP_WEBSITES', 'DATA_EXPORTERS', 'DB_SERVER_VERSION', 'EAV_INDEXER',
        'EAV_ORPHANS', 'EAV_REDUNDANT_STORE_VALUES', 'INDEXER_MODE', 'INDEXER_STATE', 'INDEXER_STUCK',
        'MARIADB_OPTIMIZER', 'MVIEW_BACKLOG', 'MYSQL_VARS', 'PARALLEL_THREADS', 'PRICE_DIMENSIONS',
        'REINDEX_CHURN', 'REINDEX_REGRESSION', 'TABLE_STATS',
    ];

    /**
     * SPEC codes not implemented yet (remove each one when its check is added to the pool)
     */
    private const PENDING_CODES = [];

    /**
     * Only ReadOnlyConnection may depend on these
     */
    private const DIRECT_DB_TYPES = [ResourceConnection::class, AdapterInterface::class];

    /**
     * File driver methods that change the filesystem
     */
    private const FILE_WRITE_METHODS = [
        'filePutContents', 'deleteFile', 'deleteDirectory', 'createDirectory', 'rename', 'copy', 'symlink',
        'changePermissions', 'changePermissionsRecursively', 'touch', 'fileOpen', 'fileWrite', 'filePutCsv',
    ];

    /**
     * @var string[]
     */
    private array $forbiddenDependencies = [];

    /**
     * @var string[]
     */
    private array $fileWrites = [];

    /**
     * @var ResourceConnection|null
     */
    private ?ResourceConnection $resource = null;

    /**
     * @var ReadOnlyConnection|null
     */
    private ?ReadOnlyConnection $connection = null;

    /**
     * Whether the check under test received the read-only connection (directly or through a provider)
     *
     * @var bool
     */
    private bool $usesConnection = false;

    /**
     * Exception thrown by the check under test, for diagnostics
     *
     * @var string
     */
    private string $checkError = '';

    /**
     * Results of the check under test
     *
     * @var array<mixed>
     */
    private array $results = [];

    public function testPoolContainsTheSpecChecksUnderTheirOwnCode(): void
    {
        $pool = self::pool();
        $expected = array_values(array_diff(self::SPEC_CODES, self::PENDING_CODES));
        sort($expected);

        $codes = array_keys($pool);
        sort($codes);
        $this->assertSame($expected, $codes);
        foreach ($pool as $code => $class) {
            $this->assertSame($code, $this->buildCheck($class)->getCode(), $class);
        }
    }

    public function testEveryPoolCheckIsDocumentedInTheReadme(): void
    {
        $readme = (string)file_get_contents(self::MODULE_DIR . '/README.md');

        foreach (array_keys(self::pool()) as $code) {
            $this->assertStringContainsString('| `' . $code . '` |', $readme, $code . ' missing in README');
        }
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('poolProvider')]
    public function testCheckNeverWrites(string $class): void
    {
        $this->exercise($class);

        $this->assertNoWriteAttempts();
        $this->assertSame([], $this->forbiddenDependencies, 'Direct DB dependencies bypass ReadOnlyConnection');
        $this->assertSame([], $this->fileWrites, 'Filesystem writes');
        $this->assertSame('', $this->checkError, 'Check did not complete');
        $this->assertNotEmpty($this->results);
        $this->assertContainsOnlyInstancesOf(CheckResult::class, $this->results);
        if ($this->usesConnection) {
            $this->assertNotEmpty(
                $this->writeGuardQueries,
                'Check has DB access but ran no query: not exercised. ' . $this->checkError
            );
        }
    }

    /**
     * @return array<string,array{class-string}>
     */
    public static function poolProvider(): array
    {
        return array_map(static fn (string $class): array => [$class], self::pool());
    }

    public function testGuardDetectsAWriteRejectedAndSwallowedByTheCheck(): void
    {
        $this->exercise(SwallowedWriteCheck::class);

        $this->assertCount(1, $this->writeGuardViolations);
        $this->assertStringStartsWith('rejected by ReadOnlyConnection: DELETE FROM', $this->writeGuardViolations[0]);
    }

    public function testGuardDetectsAWriteThroughTheAdapterAndTheDirectDependency(): void
    {
        $this->exercise(DirectAdapterCheck::class);

        $this->assertSame(['method insert()'], $this->writeGuardViolations);
        $this->assertSame(
            [DirectAdapterCheck::class . '::$resource (' . ResourceConnection::class . ')'],
            $this->forbiddenDependencies
        );
    }

    /**
     * Build and run a check against an empty, write-rejecting database
     *
     * @param class-string $class
     * @return void
     */
    private function exercise(string $class): void
    {
        $check = $this->buildCheck($class);
        try {
            $this->results = $check->check(new DoctorContext());
        } catch (\Throwable $e) {
            $this->checkError = get_class($e) . ': ' . $e->getMessage();
            // Reported by the caller; rejected writes are recorded even when a check swallows them
            if ($e instanceof WriteNotAllowedException) {
                throw $e;
            }
        }
    }

    /**
     * Check classes of the Doctor pool in etc/di.xml, by code
     *
     * @return array<string,class-string>
     */
    private static function pool(): array
    {
        $checks = self::typeArguments(Doctor::class)['checks'] ?? [];
        return array_map(
            static function (array $object): string {
                $class = (string)$object['object'];
                if (!class_exists($class)) {
                    throw new \LogicException('Unknown check class ' . $class);
                }
                return $class;
            },
            $checks
        );
    }

    /**
     * Constructor arguments configured for a type in etc/di.xml
     *
     * @param string $class
     * @return array<string,mixed> init_parameter arguments are left out
     */
    private static function typeArguments(string $class): array
    {
        $xml = simplexml_load_file(self::MODULE_DIR . '/etc/di.xml');
        if ($xml === false) {
            throw new \LogicException('Invalid etc/di.xml');
        }
        $arguments = [];
        foreach ($xml->xpath(sprintf('/config/type[@name="%s"]/arguments/argument', $class)) ?: [] as $node) {
            if ((string)$node->attributes(self::XSI)['type'] !== 'init_parameter') {
                $arguments[(string)$node['name']] = self::convert($node);
            }
        }
        return $arguments;
    }

    /**
     * Convert a di.xml argument or item node like the object manager does
     *
     * @param \SimpleXMLElement $node
     * @return mixed objects are returned as ['object' => class name]
     */
    private static function convert(\SimpleXMLElement $node): mixed
    {
        $value = trim((string)$node);
        return match ((string)$node->attributes(self::XSI)['type']) {
            'array' => array_combine(
                array_map(static fn ($item): string => (string)$item['name'], iterator_to_array($node->item, false)),
                array_map(static fn ($item): mixed => self::convert($item), iterator_to_array($node->item, false))
            ),
            'number' => str_contains($value, '.') ? (float)$value : (int)$value,
            'boolean' => in_array(strtolower($value), ['1', 'true'], true),
            'null' => null,
            'object' => ['object' => $value],
            default => $value,
        };
    }

    /**
     * Build a check of the pool
     *
     * @param class-string $class
     * @return CheckInterface
     */
    private function buildCheck(string $class): CheckInterface
    {
        $check = $this->build($class);
        if (!$check instanceof CheckInterface) {
            throw new \LogicException($class . ' does not implement CheckInterface');
        }
        return $check;
    }

    /**
     * Build a class by reflection: di.xml arguments, guarded DB and filesystem, Orangecat classes built
     * recursively, stubs for everything else
     *
     * @param class-string $class
     * @return object
     */
    private function build(string $class): object
    {
        $reflection = new \ReflectionClass($class);
        $configured = self::typeArguments($class);
        $args = [];
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $args[] = $this->resolve($class, $parameter, $configured);
        }
        return $reflection->newInstanceArgs($args);
    }

    /**
     * @param string $class
     * @param \ReflectionParameter $parameter
     * @param array<string,mixed> $configured
     * @return mixed
     */
    private function resolve(string $class, \ReflectionParameter $parameter, array $configured): mixed
    {
        $name = $parameter->getName();
        if (array_key_exists($name, $configured)) {
            $value = $configured[$name];
            if (is_array($value) && isset($value['object']) && count($value) === 1) {
                $object = (string)$value['object'];
                if (!class_exists($object)) {
                    throw new \LogicException('Unknown class ' . $object);
                }
                return $this->build($object);
            }
            return $value;
        }

        $type = $parameter->getType();
        if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
            $typeName = $type->getName();
            if (!class_exists($typeName) && !interface_exists($typeName)) {
                throw new \LogicException(sprintf('Unknown type %s of %s::$%s', $typeName, $class, $name));
            }
            if (in_array($typeName, self::DIRECT_DB_TYPES, true)) {
                $this->forbiddenDependencies[] = sprintf('%s::$%s (%s)', $class, $name, $typeName);
                return $typeName === ResourceConnection::class
                    ? $this->resource()
                    : $this->resource()->getConnection();
            }
            return match (true) {
                $typeName === ReadOnlyConnection::class => $this->connection(),
                $typeName === File::class => $this->guardedFile(),
                str_starts_with($typeName, 'Orangecat\\') && !(new \ReflectionClass($typeName))->isAbstract()
                    => $this->build($typeName),
                default => $this->stub($typeName),
            };
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }
        if ($parameter->allowsNull()) {
            return null;
        }
        throw new \LogicException(sprintf('Cannot resolve %s::$%s', $class, $name));
    }

    /**
     * Stub a Magento dependency.
     *
     * Methods without a declared return type return null on a stub; the services below get the minimum the
     * core always returns, so checks reach their queries.
     *
     * @param class-string $type
     * @return object
     */
    private function stub(string $type): object
    {
        $stub = $this->createStub($type);
        if ($stub instanceof CronConfig) {
            $stub->method('getJobs')->willReturn([
                'default' => ['catalogrule_apply_all' => ['schedule' => '0 1 * * *']],
                'index' => ['indexer_update_all_views' => ['schedule' => '* * * * *']],
            ]);
        }
        if ($stub instanceof IndexerConfig) {
            $stub->method('getIndexers')->willReturn([
                'catalog_product_price' => ['view_id' => 'catalog_product_price'],
                'sales_order_data_exporter' => ['view_id' => 'sales_order_data_exporter'],
            ]);
        }
        if ($stub instanceof ScopeConfigInterface) {
            // Passive monitor on so REINDEX_REGRESSION reaches its queries; everything else stays unset
            $stub->method('getValue')->willReturnCallback(
                static fn (string $path): ?string => $path === Config::XML_PATH_MONITOR_ENABLED ? '1' : null
            );
        }
        if ($stub instanceof DirectoryList) {
            $stub->method('getRoot')->willReturn('/nonexistent-magento-root');
        }
        if ($stub instanceof MetadataPool) {
            $metadata = $this->createStub(EntityMetadataInterface::class);
            $metadata->method('getLinkField')->willReturn('entity_id');
            $stub->method('getMetadata')->willReturn($metadata);
        }
        return $stub;
    }

    /**
     * Resource connection over a fake adapter that answers every read with an empty result
     *
     * @return ResourceConnection
     */
    private function resource(): ResourceConnection
    {
        return $this->resource ??= $this->createResourceConnection(
            $this->createWriteGuardAdapter(static fn (string $sql, string $method): mixed => match ($method) {
                'fetchAll', 'fetchCol', 'fetchPairs', 'fetchAssoc' => [],
                default => false,
            })
        );
    }

    /**
     * Read-only connection that records every rejected statement, so a check cannot hide it
     *
     * @return ReadOnlyConnection
     */
    private function connection(): ReadOnlyConnection
    {
        $this->usesConnection = true;
        return $this->connection ??= new class ($this->resource(), function (string $sql): void {
            $this->writeGuardViolations[] = 'rejected by ReadOnlyConnection: ' . $sql;
        }) extends ReadOnlyConnection {
            /**
             * @var callable(string): void
             */
            private $onReject;

            /**
             * @param ResourceConnection $resource
             * @param callable(string): void $onReject
             */
            public function __construct(ResourceConnection $resource, callable $onReject)
            {
                parent::__construct($resource);
                $this->onReject = $onReject;
            }

            /**
             * @inheritdoc
             */
            public function fetchAll(string $sql, array $bind = []): array
            {
                return $this->guard(fn () => parent::fetchAll($sql, $bind), $sql);
            }

            /**
             * @inheritdoc
             */
            public function fetchRow(string $sql, array $bind = []): array|false
            {
                return $this->guard(fn () => parent::fetchRow($sql, $bind), $sql);
            }

            /**
             * @inheritdoc
             */
            public function fetchCol(string $sql, array $bind = []): array
            {
                return $this->guard(fn () => parent::fetchCol($sql, $bind), $sql);
            }

            /**
             * @inheritdoc
             */
            public function fetchPairs(string $sql, array $bind = []): array
            {
                return $this->guard(fn () => parent::fetchPairs($sql, $bind), $sql);
            }

            /**
             * @inheritdoc
             */
            public function fetchOne(string $sql, array $bind = []): mixed
            {
                return $this->guard(fn () => parent::fetchOne($sql, $bind), $sql);
            }

            /**
             * @param callable(): mixed $fetch
             * @param string $sql
             * @return mixed
             */
            private function guard(callable $fetch, string $sql): mixed
            {
                try {
                    return $fetch();
                } catch (WriteNotAllowedException $e) {
                    ($this->onReject)($sql);
                    throw $e;
                }
            }
        };
    }

    /**
     * File driver stub that records any write; reads return empty results
     *
     * @return File
     */
    private function guardedFile(): File
    {
        $file = $this->createStub(File::class);
        foreach (self::FILE_WRITE_METHODS as $method) {
            $file->method($method)->willReturnCallback(function () use ($method): never {
                $this->fileWrites[] = $method;
                throw new \LogicException('Filesystem write rejected: ' . $method);
            });
        }
        return $file;
    }
}
