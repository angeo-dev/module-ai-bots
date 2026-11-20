<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Console\Command;

use Angeo\AiBots\Model\Refresher;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento angeo:bots:refresh — download IP lists and signing keys now.
 */
class RefreshCommand extends Command
{
    public function __construct(
        private readonly Refresher $refresher
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName('angeo:bots:refresh')
            ->setDescription('Download the vendor IP lists and Web Bot Auth keys of the known AI bots');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $failed = 0;
        foreach ($this->refresher->refresh() as $url => $line) {
            $failed += str_starts_with($line, 'failed') ? 1 : 0;
            $output->writeln(sprintf('%-8s %s  %s', str_starts_with($line, 'ok') ? '<info>ok</info>' : '<error>fail</error>', $url, $line));
        }
        $output->writeln($failed === 0 ? 'All lists downloaded.' : sprintf('%d failed. The previous copy is kept for those.', $failed));
        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
