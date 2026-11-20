<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Console\Command;

use Angeo\AiBots\Model\Verify\StoredLists;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento angeo:bots:status — what was downloaded, and when.
 */
class StatusCommand extends Command
{
    public function __construct(
        private readonly StoredLists $lists
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName('angeo:bots:status')
            ->setDescription('Show when the IP lists and signing keys were last downloaded');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $status = $this->lists->status();
        if ($status === null) {
            $output->writeln('Never refreshed. Run bin/magento angeo:bots:refresh, or wait for cron.');
            return Command::FAILURE;
        }
        $output->writeln(sprintf(
            'Last refresh: %s UTC, %d ok, %d failed.',
            gmdate('Y-m-d H:i', (int) $status['ran_at']),
            (int) ($status['ok'] ?? 0),
            (int) ($status['failed'] ?? 0)
        ));
        foreach ($this->lists->allRanges() as $url => $row) {
            $output->writeln(sprintf(
                '  IP list   %s  %d prefixes, %s',
                $url,
                count((array) ($row['prefixes'] ?? [])),
                gmdate('Y-m-d', (int) ($row['fetched_at'] ?? 0))
            ));
        }
        foreach ($this->lists->allKeys() as $url => $row) {
            $output->writeln(sprintf(
                '  Keys      %s  %d keys, %s',
                $url,
                count((array) ($row['keys'] ?? [])),
                gmdate('Y-m-d', (int) ($row['fetched_at'] ?? 0))
            ));
        }
        foreach ((array) ($status['errors'] ?? []) as $url => $error) {
            $output->writeln(sprintf('  <error>%s</error>  %s', $url, $error));
        }
        return Command::SUCCESS;
    }
}
