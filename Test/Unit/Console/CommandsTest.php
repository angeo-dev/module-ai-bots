<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Test\Unit\Console;

use Angeo\AiBots\Console\Command\ListCommand;
use Angeo\AiBots\Console\Command\VerifyIpCommand;
use Angeo\AiBots\Console\Command\VerifyRequestCommand;
use Angeo\AiBots\Model\BotIdentifier;
use Angeo\AiBots\Model\IncomingRequestFactory;
use Angeo\AiBots\Model\Refresher;
use Angeo\AiBots\Model\Signature\SignatureAgentHeader;
use Angeo\AiBots\Model\Signature\SignatureBase;
use Angeo\AiBots\Model\Signature\SignatureParser;
use Angeo\AiBots\Model\Signature\StructuredFields;
use Angeo\AiBots\Model\Verify\BotVerifier;
use Angeo\AiBots\Model\Verify\CidrMatcher;
use Angeo\AiBots\Model\Verify\IpRangeVerifier;
use Angeo\AiBots\Model\Verify\StoredLists;
use Angeo\AiBots\Model\Verify\WebBotAuthVerifier;
use Angeo\AiBots\Test\Unit\Model\BotRegistryTest;
use Angeo\AiBots\Test\Unit\Model\Signature\Rfc9421VectorTest;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class CommandsTest extends TestCase
{
    private function lists(): StoredLists
    {
        $lists = $this->createMock(StoredLists::class);
        $lists->method('prefixes')->willReturnMap([['https://openai.com/gptbot.json', ['20.171.206.0/24']]]);
        $lists->method('keys')->willReturn(null);
        return $lists;
    }

    public function testListAsJson(): void
    {
        $tester = new CommandTester(new ListCommand(BotRegistryTest::registry()));
        self::assertSame(0, $tester->execute(['--format' => 'json', '--category' => 'agent']));
        $data = json_decode($tester->getDisplay(), true);
        self::assertSame(['chatgpt_agent', 'google_agent', 'grok_agent'], array_column($data['bots'], 'id'));
    }

    public function testVerifyIp(): void
    {
        $command = new VerifyIpCommand(BotRegistryTest::registry(), new IpRangeVerifier($this->lists(), new CidrMatcher()));
        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute(['bot' => 'gptbot', 'ip' => '20.171.206.4']));
        self::assertStringContainsString('VERIFIED', $tester->getDisplay());
        self::assertSame(1, $tester->execute(['bot' => 'GPTBot/1.3', 'ip' => '8.8.8.8']));
        self::assertStringContainsString('INVALID', $tester->getDisplay());
        self::assertSame(2, $tester->execute(['bot' => 'nobody', 'ip' => '8.8.8.8']));
    }

    public function testVerifyRequestWithFetchedKeys(): void
    {
        $fields = new StructuredFields();
        $now = time();
        $input = sprintf(
            'sig1=("@authority" "signature-agent";key="sig1");created=%d;expires=%d;keyid="%s";tag="web-bot-auth"',
            $now - 5,
            $now + 300,
            'poqkLGiymh_W0uP6PZFw-dvez3QJT5SolqXBCW38r0U'
        );
        $base = "\"@authority\": shop.example\n\"signature-agent\";key=\"sig1\": \"https://chatgpt.com\"\n\"@signature-params\": " . substr($input, 5);
        $signature = base64_encode(sodium_crypto_sign_detached($base, Rfc9421VectorTest::secretKey()));

        $refresher = $this->createMock(Refresher::class);
        $refresher->expects(self::once())->method('downloadKeys')
            ->with('https://chatgpt.com/.well-known/http-message-signatures-directory')
            ->willReturn(['poqkLGiymh_W0uP6PZFw-dvez3QJT5SolqXBCW38r0U' => Rfc9421VectorTest::publicKey()]);
        $lists = $this->lists();
        $agentHeader = new SignatureAgentHeader($fields);
        $wba = new WebBotAuthVerifier(new SignatureParser($fields), $agentHeader, new SignatureBase($fields), $lists);
        $command = new VerifyRequestCommand(
            new IncomingRequestFactory($this->createMock(RemoteAddress::class)),
            new BotIdentifier(BotRegistryTest::registry(), $agentHeader),
            new BotVerifier($wba, new IpRangeVerifier($lists, new CidrMatcher())),
            $wba,
            $refresher
        );
        $tester = new CommandTester($command);
        $code = $tester->execute([
            '--url' => 'https://shop.example/p.html',
            '--header' => [
                'User-Agent: Mozilla/5.0 Chrome/141',
                'Signature-Agent: sig1="https://chatgpt.com"',
                'Signature-Input: ' . $input,
                'Signature: sig1=:' . $signature . ':',
            ],
            '--fetch-keys' => true,
        ]);
        self::assertSame(0, $code, $tester->getDisplay());
        self::assertStringContainsString('Claims to be: chatgpt_agent', $tester->getDisplay());
        self::assertStringContainsString('Result: VERIFIED (web_bot_auth)', $tester->getDisplay());
    }
}
