<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Unit\Model\Indexer\Price;

use Orangecat\IndexerBoost\Model\Indexer\Price\FullReindexScope;
use PHPUnit\Framework\TestCase;

class FullReindexScopeTest extends TestCase
{
    public function testIsActiveOnlyWhileTheCallableRunsEvenWhenItThrows(): void
    {
        $scope = new FullReindexScope();
        $seen = null;

        $error = $this->failure(function () use ($scope, &$seen): void {
            $scope->run(function () use ($scope, &$seen): void {
                $seen = $scope->isActive();
                throw new \RuntimeException('reindex failed');
            });
        });

        $this->assertSame('reindex failed', $error);
        $this->assertTrue($seen);
        $this->assertFalse($scope->isActive());
    }

    /**
     * Message of the exception the callable throws
     *
     * @param callable $run
     * @return string|null
     */
    private function failure(callable $run): ?string
    {
        try {
            $run();
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }
        return null;
    }
}
