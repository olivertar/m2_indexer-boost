<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Console\Command;

use Orangecat\IndexerBoost\Model\Indexer\Toggle;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Switch between the core and the Indexer Boost implementation of an indexer (SPEC 6.1)
 */
class ToggleCommand extends Command
{
    private const NAME = 'indexer-boost:toggle';
    private const ARGUMENT_INDEXER = 'indexer';
    private const ARGUMENT_STATE = 'state';
    private const STATES = ['on' => true, 'off' => false];

    /**
     * @param Toggle $toggle
     */
    public function __construct(
        private readonly Toggle $toggle
    ) {
        parent::__construct();
    }

    /**
     * @inheritdoc
     */
    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setDescription('Use the Indexer Boost implementation of an indexer (on) or the core one (off)')
            ->addArgument(self::ARGUMENT_INDEXER, InputArgument::REQUIRED, 'Indexer ID')
            ->addArgument(self::ARGUMENT_STATE, InputArgument::REQUIRED, 'on|off');
        parent::configure();
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $indexerId = (string)$input->getArgument(self::ARGUMENT_INDEXER);
        $state = (string)$input->getArgument(self::ARGUMENT_STATE);
        if (!isset(self::STATES[$state])) {
            $output->writeln('<error>State must be on or off</error>');
            return Command::INVALID;
        }
        try {
            $this->toggle->set($indexerId, self::STATES[$state]);
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::INVALID;
        }
        $output->writeln(sprintf('%s: Indexer Boost implementation %s', $indexerId, $state));
        return Command::SUCCESS;
    }
}
