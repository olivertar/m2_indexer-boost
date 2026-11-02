<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Doctor\Check;

use Magento\Framework\Config\ScopeInterface;
use Magento\Framework\Interception\PluginListInterface;
use Magento\Framework\ObjectManager\ConfigInterface;
use Orangecat\IndexerBoost\Model\Doctor\CheckInterface;
use Orangecat\IndexerBoost\Model\Doctor\CheckResult;
use Orangecat\IndexerBoost\Model\Doctor\DoctorContext;
use Orangecat\IndexerBoost\Model\Doctor\Severity;

/**
 * Other modules that intercept or replace the core classes the Indexer Boost rewrites hook into
 *
 * A third-party plugin on the same method may run before ours or skip it, and a preference may override the method
 * without calling the core one: either can change what the rewrite does. Plugins of Magento itself are the ones the
 * rewrites are tested with, so only plugins of other vendors count. Heavy (CLI only): it switches the configuration
 * scope of the process to read the plugins of every area the indexers run in.
 */
class BoostCompatibilityCheck implements CheckInterface
{
    public const CODE = 'BOOST_COMPATIBILITY';
    private const KNOWN_NAMESPACES = ['Magento\\', 'Orangecat\\IndexerBoost\\'];

    /**
     * @param PluginListInterface $pluginList
     * @param ConfigInterface $diConfig
     * @param ScopeInterface $scope
     * @param array<string,string> $interceptedMethods core class => intercepted method
     * @param string[] $areas configuration scopes the indexers run in
     */
    public function __construct(
        private readonly PluginListInterface $pluginList,
        private readonly ConfigInterface $diConfig,
        private readonly ScopeInterface $scope,
        private readonly array $interceptedMethods = [],
        private readonly array $areas = ['global', 'adminhtml', 'crontab']
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
        $foreign = [];
        $current = $this->scope->getCurrentScope();
        try {
            foreach ($this->areas as $area) {
                $this->scope->setCurrentScope($area);
                foreach ($this->interceptedMethods as $type => $method) {
                    $foreign[$type][] = $this->foreignPlugins($type, $method);
                }
            }
        } finally {
            $this->scope->setCurrentScope($current);
        }

        $foreign = array_map(static fn (array $lists): array => array_unique(array_merge([], ...$lists)), $foreign);
        $results = [];
        foreach ($this->interceptedMethods as $type => $method) {
            $plugins = $foreign[$type] ?? [];
            if ($plugins) {
                $results[] = $this->warning(
                    sprintf('%s::%s() is also intercepted by %s', $type, $method, implode(', ', $plugins)),
                    $type
                );
            }
            $preference = ltrim((string)$this->diConfig->getPreference($type), '\\');
            if ($preference !== '' && $preference !== $type) {
                $results[] = $this->warning(sprintf('%s is replaced by %s (preference)', $type, $preference), $type);
            }
        }

        return $results ?: [
            new CheckResult(
                Severity::Ok,
                self::CODE,
                sprintf(
                    'No other module intercepts or replaces the %d core classes Indexer Boost hooks into (areas: %s)',
                    count($this->interceptedMethods),
                    implode(', ', $this->areas)
                )
            ),
        ];
    }

    /**
     * Plugins of other vendors on one method, as "name (class)"
     *
     * @param string $type
     * @param string $method
     * @return string[]
     */
    private function foreignPlugins(string $type, string $method): array
    {
        $codes = [];
        $code = null;
        $steps = [];
        // Every step of the chain lists the before and after plugins, and the next around plugin
        do {
            $next = $code === null ? $this->pluginList->getNext($type, $method) : $this->pluginList->getNext(
                $type,
                $method,
                $code
            );
            if (!is_array($next)) {
                break;
            }
            $steps[] = (array)($next[1] ?? []);
            $steps[] = (array)($next[4] ?? []);
            $code = isset($next[2]) && is_string($next[2]) ? $next[2] : null;
            if ($code !== null) {
                $codes[] = $code;
            }
        } while ($code !== null);

        $foreign = [];
        foreach (array_unique(array_merge($codes, ...$steps)) as $pluginCode) {
            $plugin = $this->pluginList->getPlugin($type, (string)$pluginCode);
            if (!is_object($plugin)) {
                continue;
            }
            $class = $plugin::class;
            if (!$this->isKnown($class)) {
                $foreign[] = sprintf('%s (%s)', $pluginCode, $class);
            }
        }
        return $foreign;
    }

    /**
     * Whether a class belongs to Magento or to Indexer Boost
     *
     * @param string $class
     * @return bool
     */
    private function isKnown(string $class): bool
    {
        foreach (self::KNOWN_NAMESPACES as $namespace) {
            if (str_starts_with($class, $namespace)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Warning for one core class
     *
     * @param string $message
     * @param string $type
     * @return CheckResult
     */
    private function warning(string $message, string $type): CheckResult
    {
        return new CheckResult(
            Severity::Warn,
            self::CODE,
            $message,
            'Indexer Boost rewrites are checked against the core code and the plugins of Magento only. Read what '
            . 'the other module does to this class, and compare the output of the rewrite with the core one on a '
            . 'copy of the database (indexer-bench:snapshot --diff) before turning it on.',
            'bin/magento dev:di:info ' . $type,
            ['class' => $type]
        );
    }
}
