<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Console\Command;

use Angeo\AiBots\Api\BotRegistryInterface;
use Angeo\AiBots\Model\Verify\IpRangeVerifier;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento angeo:bots:verify-ip gptbot 20.171.207.10
 *
 * Use case: an access log line says "GPTBot". Is it really OpenAI?
 */
class VerifyIpCommand extends Command
{
    public function __construct(
        private readonly BotRegistryInterface $registry,
        private readonly IpRangeVerifier $verifier
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName('angeo:bots:verify-ip')
            ->setDescription('Check an IP address against the list a bot operator publishes')
            ->addArgument('bot', InputArgument::REQUIRED, 'Bot id (see angeo:bots:list) or a user agent string')
            ->addArgument('ip', InputArgument::REQUIRED, 'IPv4 or IPv6 address');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) $input->getArgument('bot');
        $bot = $this->registry->get($name) ?? $this->registry->match($name);
        if ($bot === null) {
            $output->writeln(sprintf('<error>Unknown bot "%s". See bin/magento angeo:bots:list.</error>', $name));
            return Command::INVALID;
        }
        $result = $this->verifier->verify($bot, (string) $input->getArgument('ip'));
        $output->writeln(sprintf('%s: %s', $bot->getName(), strtoupper($result->getState())));
        $output->writeln($result->getReason());
        return $result->isInvalid() ? Command::FAILURE : Command::SUCCESS;
    }
}
