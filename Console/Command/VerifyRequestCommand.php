<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Console\Command;

use Angeo\AiBots\Api\BotIdentifierInterface;
use Angeo\AiBots\Api\BotVerifierInterface;
use Angeo\AiBots\Model\IncomingRequestFactory;
use Angeo\AiBots\Model\Refresher;
use Angeo\AiBots\Model\Verify\WebBotAuthVerifier;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento angeo:bots:verify-request --url=https://shop.example/p.html \
 *     --ip=1.2.3.4 -H 'User-Agent: …' -H 'Signature-Agent: …' \
 *     -H 'Signature-Input: …' -H 'Signature: …' [--fetch-keys]
 *
 * Paste the headers of one logged request and see who it is and whether the
 * claim holds. --fetch-keys downloads the signer's key directory now, under
 * the same SSRF rules as the daily refresh; use it for agents that are not
 * in the list.
 */
class VerifyRequestCommand extends Command
{
    public function __construct(
        private readonly IncomingRequestFactory $requestFactory,
        private readonly BotIdentifierInterface $identifier,
        private readonly BotVerifierInterface $verifier,
        private readonly WebBotAuthVerifier $webBotAuth,
        private readonly Refresher $refresher
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName('angeo:bots:verify-request')
            ->setDescription('Identify one request from its headers and check its claim (IP list, Web Bot Auth)')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Full URL the request asked for', 'https://localhost/')
            ->addOption('method', null, InputOption::VALUE_REQUIRED, 'HTTP method', 'GET')
            ->addOption('ip', null, InputOption::VALUE_REQUIRED, 'Client IP address', '')
            ->addOption('header', 'H', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, '"Name: value", repeat for each header')
            ->addOption('fetch-keys', null, InputOption::VALUE_NONE, 'Download the signer\'s key directory now');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $headers = [];
        foreach ((array) $input->getOption('header') as $line) {
            $pos = strpos((string) $line, ':');
            if ($pos === false) {
                $output->writeln(sprintf('<error>Header "%s" has no ":".</error>', $line));
                return Command::INVALID;
            }
            $headers[strtolower(trim(substr((string) $line, 0, $pos)))] = trim(substr((string) $line, $pos + 1));
        }
        $url = (string) $input->getOption('url');
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            $output->writeln('<error>--url must be a full URL.</error>');
            return Command::INVALID;
        }
        $authority = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $target = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $request = $this->requestFactory->create(
            $headers,
            (string) $input->getOption('ip'),
            (string) $input->getOption('method'),
            (string) ($parts['scheme'] ?? 'https'),
            $authority,
            $target
        );

        $identity = $this->identifier->identify($request);
        $output->writeln(sprintf(
            'Claims to be: %s (%s, %s, %s)%s',
            $identity->getName(),
            $identity->getOperator() ?: 'unknown operator',
            $identity->getCategory(),
            $identity->getKind(),
            $identity->isSigned() ? ', signed by ' . $identity->getSignatureAgent() : ''
        ));

        $result = $this->verifier->verify($identity, $request);
        if ($input->getOption('fetch-keys') && $identity->isSigned() && !$result->isVerified() && !$result->isInvalid()) {
            $result = $this->webBotAuth->verify($identity, $request, function (string $directory) use ($output): ?array {
                try {
                    return $this->refresher->downloadKeys($directory);
                } catch (\Throwable $e) {
                    $output->writeln(sprintf('<comment>Could not download %s: %s</comment>', $directory, $e->getMessage()));
                    return null;
                }
            });
        }

        $output->writeln(sprintf('Result: %s (%s)', strtoupper($result->getState()), $result->getMethod()));
        $output->writeln($result->getReason());
        foreach ($result->getDetails() as $key => $value) {
            $output->writeln(sprintf('  %s: %s', $key, var_export($value, true)));
        }
        return $result->isInvalid() ? Command::FAILURE : Command::SUCCESS;
    }
}
