<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Model\Repair;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Indexer\ConfigInterface;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerInterfaceFactory;
use Magento\Indexer\Model\Processor\MakeSharedIndexValid;
use Orangecat\IndexerBoost\Model\Monitor\Clock;

/**
 * Releases stuck indexers and rebuilds invalid ones. Never changes configuration.
 */
class Repairer
{
    /**
     * Verdicts an indexer may be released from
     */
    private const RELEASABLE = [StuckIndexer::STUCK, StuckIndexer::DOUBTFUL];

    /**
     * StateInterface::STATUS_SUSPENDED, a literal because 2.4.6 lacks the constant
     */
    private const STATUS_SUSPENDED = 'suspended';

    /**
     * @param StuckIndexerDetector $detector
     * @param IndexerInterfaceFactory $indexerFactory
     * @param ConfigInterface $indexerConfig
     * @param MakeSharedIndexValid $makeSharedValid
     * @param Clock $clock
     * @param string $sapi
     */
    public function __construct(
        private readonly StuckIndexerDetector $detector,
        private readonly IndexerInterfaceFactory $indexerFactory,
        private readonly ConfigInterface $indexerConfig,
        private readonly MakeSharedIndexValid $makeSharedValid,
        private readonly Clock $clock,
        private readonly string $sapi = PHP_SAPI
    ) {
    }

    /**
     * Indexers working for too long, with the verdict of the detector
     *
     * @return array<string,StuckIndexer>
     * @throws LocalizedException outside the command line
     */
    public function findStuck(): array
    {
        $this->assertCommandLine();
        return $this->detector->detect();
    }

    /**
     * Mark a stuck indexer invalid, after checking again that it is still stuck
     *
     * Time passes while the operator confirms: the lock or a running statement may have shown up meanwhile.
     *
     * @param string $indexerId
     * @return bool whether the indexer was invalidated
     * @throws LocalizedException outside the command line
     */
    public function invalidate(string $indexerId): bool
    {
        $this->assertCommandLine();
        $stuck = $this->detector->detect()[$indexerId] ?? null;
        if ($stuck === null || !in_array($stuck->verdict, self::RELEASABLE, true)) {
            return false;
        }
        $this->load($indexerId)->invalidate();
        return true;
    }

    /**
     * Full reindex of every invalid indexer, in the dependency order of the indexer config
     *
     * @return ReindexOutcome[]
     * @throws LocalizedException outside the command line
     */
    public function reindexInvalid(): array
    {
        $this->assertCommandLine();
        $indexers = $this->indexerConfig->getIndexers();
        // With the application lock the core reports "working" without lock as invalid; invalidate() decides those
        $working = $this->detector->getWorkingIndexerIds();
        $outcomes = [];
        foreach ($indexers as $indexerId => $config) {
            $indexer = $this->load($indexerId);
            $sharedIndex = $config['shared_index'] ?? null;
            if (!$indexer->isInvalid()
                || in_array($indexerId, $working, true)
                || ($sharedIndex !== null && $this->isSharedIndexSuspended($sharedIndex, $indexers))
            ) {
                continue;
            }
            $start = $this->clock->monotonic();
            $error = null;
            try {
                $indexer->reindexAll();
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
            $outcomes[] = new ReindexOutcome($indexerId, round($this->clock->monotonic() - $start, 3), $error);
            // Like Processor::reindexAllInvalid: the other indexers of a shared index are now built too
            if ($error === null && $sharedIndex !== null && $this->load($indexerId)->isValid()) {
                $this->makeSharedValid->execute($sharedIndex);
            }
        }
        return $outcomes;
    }

    /**
     * Fresh copy of an indexer and its state
     *
     * @param string $indexerId
     * @return IndexerInterface
     */
    private function load(string $indexerId): IndexerInterface
    {
        return $this->indexerFactory->create()->load($indexerId);
    }

    /**
     * Whether any indexer of the shared index is suspended, as Processor::reindexAllInvalid checks
     *
     * @param string $sharedIndex
     * @param array<string,array<string,mixed>> $indexers
     * @return bool
     */
    private function isSharedIndexSuspended(string $sharedIndex, array $indexers): bool
    {
        foreach ($indexers as $indexerId => $config) {
            if (($config['shared_index'] ?? null) === $sharedIndex
                && $this->load($indexerId)->getStatus() === self::STATUS_SUSPENDED
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Refuse to run from a web request
     *
     * @return void
     * @throws LocalizedException
     */
    private function assertCommandLine(): void
    {
        if ($this->sapi !== 'cli') {
            throw new LocalizedException(__('Indexer repair only runs from the command line.'));
        }
    }
}
