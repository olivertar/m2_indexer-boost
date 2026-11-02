<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Plugin\Indexer\Fixture;

use Magento\Framework\Interception\DefinitionInterface;
use Magento\Framework\Interception\PluginListInterface;

/**
 * Plugin list for "around" plugins that, like Magento, applies a plugin declared on a type to all its subtypes
 */
class InheritingPluginList implements PluginListInterface
{
    /**
     * @param array<string,array<string,object>> $plugins declared type => plugin code => plugin, in sort order
     */
    public function __construct(
        private readonly array $plugins
    ) {
    }

    /**
     * Next around plugin after $code, or the first one
     *
     * @param string $type
     * @param string $method
     * @param string|null $code
     * @return array<int,string>|null
     */
    public function getNext($type, $method, $code = null)
    {
        $codes = array_keys($this->applicable((string)$type, (string)$method));
        $position = $code === null ? 0 : (int)array_search($code, $codes, true) + 1;

        return isset($codes[$position]) ? [DefinitionInterface::LISTENER_AROUND => $codes[$position]] : null;
    }

    /**
     * Plugin instance by code
     *
     * @param string $type
     * @param string $code
     * @return object|null
     */
    public function getPlugin($type, $code)
    {
        return $this->applicable((string)$type, null)[$code] ?? null;
    }

    /**
     * Plugins of the type and its parents/interfaces, optionally only those with an around method for $method
     *
     * @param string $type
     * @param string|null $method
     * @return array<string,object>
     */
    private function applicable(string $type, ?string $method): array
    {
        $result = [];
        foreach ($this->plugins as $declaredType => $plugins) {
            if (!is_a($type, $declaredType, true)) {
                continue;
            }
            foreach ($plugins as $code => $plugin) {
                if ($method === null || method_exists($plugin, 'around' . ucfirst($method))) {
                    $result[$code] = $plugin;
                }
            }
        }
        return $result;
    }
}
