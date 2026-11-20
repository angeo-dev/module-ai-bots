<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Test\Unit\Model\Verify;

use Angeo\AiBots\Api\Data\VerificationResultInterface as Result;
use Angeo\AiBots\Model\BotIdentifier;
use Angeo\AiBots\Model\IncomingRequest;
use Angeo\AiBots\Model\Signature\SignatureAgentHeader;
use Angeo\AiBots\Model\Signature\StructuredFields;
use Angeo\AiBots\Model\Verify\BotVerifier;
use Angeo\AiBots\Model\Verify\IpRangeVerifier;
use Angeo\AiBots\Model\Verify\VerificationResult;
use Angeo\AiBots\Model\Verify\WebBotAuthVerifier;
use Angeo\AiBots\Test\Unit\Model\BotRegistryTest;
use PHPUnit\Framework\TestCase;

/**
 * Which proof is used, in which order.
 */
class BotVerifierTest extends TestCase
{
    private function outcome(array $headers, string $signedState, string $ipState, bool &$ipAsked = false): string
    {
        $identity = (new BotIdentifier(BotRegistryTest::registry(), new SignatureAgentHeader(new StructuredFields())))
            ->identify($request = new IncomingRequest('GET', 'https', 'shop.example', '/', $headers, '20.171.206.17'));
        $wba = $this->createMock(WebBotAuthVerifier::class);
        $wba->method('verify')->willReturn(VerificationResult::of($signedState, Result::METHOD_WEB_BOT_AUTH, 'wba'));
        $ip = $this->createMock(IpRangeVerifier::class);
        $ip->method('verify')->willReturnCallback(function () use ($ipState, &$ipAsked) {
            $ipAsked = true;
            return VerificationResult::of($ipState, Result::METHOD_IP_RANGE, 'ip');
        });
        return (new BotVerifier($wba, $ip))->verify($identity, $request)->getMethod() . ':'
            . (new BotVerifier($wba, $ip))->verify($identity, $request)->getState();
    }

    public function testUnsignedBotUsesIpList(): void
    {
        self::assertSame('ip_range:verified', $this->outcome(['user-agent' => 'GPTBot/1.3'], Result::STATE_VERIFIED, Result::STATE_VERIFIED));
    }

    public function testValidSignatureWinsWithoutIpCheck(): void
    {
        $asked = false;
        $headers = ['user-agent' => 'ChatGPT-User/1.0', 'signature-agent' => 'sig1="https://chatgpt.com"', 'signature' => 'sig1=:AA==:'];
        self::assertSame('web_bot_auth:verified', $this->outcome($headers, Result::STATE_VERIFIED, Result::STATE_INVALID, $asked));
        self::assertFalse($asked);
    }

    public function testBrokenSignatureIsInvalidEvenIfIpMatches(): void
    {
        $headers = ['user-agent' => 'ChatGPT-User/1.0', 'signature-agent' => 'sig1="https://chatgpt.com"', 'signature' => 'sig1=:AA==:'];
        self::assertSame('web_bot_auth:invalid', $this->outcome($headers, Result::STATE_INVALID, Result::STATE_VERIFIED));
    }

    public function testUncheckableSignatureFallsBackToIp(): void
    {
        $headers = ['user-agent' => 'ChatGPT-User/1.0', 'signature-agent' => 'sig1="https://chatgpt.com"', 'signature' => 'sig1=:AA==:'];
        self::assertSame('ip_range:verified', $this->outcome($headers, Result::STATE_UNVERIFIED, Result::STATE_VERIFIED));
    }

    public function testSignedAgentWithoutIpListKeepsSignatureResult(): void
    {
        $headers = ['user-agent' => 'Mozilla/5.0 Chrome/141', 'signature-agent' => 'sig1="https://chatgpt.com"', 'signature' => 'sig1=:AA==:'];
        self::assertSame('web_bot_auth:unverified', $this->outcome($headers, Result::STATE_UNVERIFIED, Result::STATE_VERIFIED));
    }

    public function testUnknownRequestIsUnsupported(): void
    {
        self::assertSame('none:unsupported', $this->outcome(['user-agent' => 'curl/8.0'], Result::STATE_VERIFIED, Result::STATE_VERIFIED));
    }

    public function testBotWithoutAnyRailIsUnsupported(): void
    {
        self::assertSame('none:unsupported', $this->outcome(['user-agent' => 'CCBot/2.0'], Result::STATE_VERIFIED, Result::STATE_VERIFIED));
    }
}
