<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Console\Command;

use Angeo\AiBots\Api\BotRegistryInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento angeo:bots:list [--category=training] [--format=json]
 */
class ListCommand extends Command
{
    public function __construct(
        private readonly BotRegistryInterface $registry
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName('angeo:bots:list')
            ->setDescription('List the known AI bots and how each can be verified')
            ->addOption('category', 'c', InputOption::VALUE_REQUIRED, 'Only one category (training, search, user_fetch, agent, ads, search_engine)')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'table or json', 'table');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $category = (string) $input->getOption('category');
        $bots = array_filter(
            $this->registry->all(),
            static fn ($bot): bool => $category === '' || $bot->getCategory() === $category
        );

        if ($input->getOption('format') === 'json') {
            $output->writeln((string) json_encode(
                ['schema_version' => 1, 'bots' => array_values(array_map(static fn ($bot) => $bot->toArray(), $bots))],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ));
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['ID', 'Name', 'Operator', 'Category', 'Check', 'Robots.txt']);
        foreach ($bots as $bot) {
            $checks = [];
            if ($bot->getIpRangesUrl() !== null) {
                $checks[] = $bot->isIpRangesShared() ? 'IP (shared)' : 'IP';
            }
            if ($bot->getSignatureAgents() !== []) {
                $checks[] = 'signature';
            }
            $robots = $bot->respectsRobots();
            $table->addRow([
                $bot->getId(),
                $bot->getName() . ($bot->getStatus() === 'deprecated' ? ' (deprecated)' : ''),
                $bot->getOperator(),
                $bot->getCategory(),
                $checks === [] ? '-' : implode(', ', $checks),
                $bot->isRobotsOnly() ? 'token only' : ($robots === null ? 'not always' : ($robots ? 'yes' : 'no')),
            ]);
        }
        $table->render();
        $output->writeln(sprintf('%d bots.', count($bots)));
        return Command::SUCCESS;
    }
}
