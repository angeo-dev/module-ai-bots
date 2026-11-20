<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Test\Unit\Model;

use Angeo\AiBots\Api\Data\IdentityInterface;
use Angeo\AiBots\Model\BotIdentifier;
use Angeo\AiBots\Model\IncomingRequest;
use Angeo\AiBots\Model\Signature\SignatureAgentHeader;
use Angeo\AiBots\Model\Signature\StructuredFields;
use PHPUnit\Framework\TestCase;

class BotIdentifierTest extends TestCase
{
    /**
     * @param array<string, string> $headers
     */
    private function identify(array $headers): IdentityInterface
    {
        return (new BotIdentifier(BotRegistryTest::registry(), new SignatureAgentHeader(new StructuredFields())))
            ->identify(new IncomingRequest('GET', 'https', 'shop.example', '/', $headers, ''));
    }

    public function testKnownUserAgent(): void
    {
        $identity = $this->identify(['User-Agent' => 'Mozilla/5.0 (compatible; PerplexityBot/1.0)']);
        self::assertSame(IdentityInterface::KIND_BOT, $identity->getKind());
        self::assertSame('perplexitybot', $identity->getName());
        self::assertSame('search', $identity->getCategory());
        self::assertFalse($identity->isSigned());
    }

    public function testSignedKnownBot(): void
    {
        $identity = $this->identify([
            'user-agent' => 'ChatGPT-User/1.0',
            'signature-agent' => 'sig1="https://chatgpt.com"',
            'signature' => 'sig1=:AA==:',
        ]);
        self::assertSame('chatgpt_user', $identity->getName());
        self::assertTrue($identity->isSigned());
        self::assertSame('https://chatgpt.com', $identity->getSignatureAgent());
    }

    public function testSignatureAgentWithoutSignatureIsNotSigned(): void
    {
        $identity = $this->identify(['user-agent' => 'Mozilla/5.0 Chrome/141', 'signature-agent' => 'sig1="https://chatgpt.com"']);
        self::assertSame(IdentityInterface::KIND_UNKNOWN, $identity->getKind());
    }

    public function testUnlistedSignedAgent(): void
    {
        $identity = $this->identify([
            'user-agent' => 'Mozilla/5.0',
            'signature-agent' => 'sig1="https://crawler-key.proximic.com"',
            'signature' => 'sig1=:AA==:',
        ]);
        self::assertSame(IdentityInterface::KIND_SIGNED_AGENT, $identity->getKind());
        self::assertSame('crawler-key.proximic.com', $identity->getName());
        self::assertSame('proximic.com', $identity->getOperator());
        self::assertSame('other', $identity->getCategory());
        self::assertNull($identity->getBot());
    }

    public function testHttpSignatureAgentIsIgnored(): void
    {
        $identity = $this->identify(['signature-agent' => 'sig1="http://chatgpt.com"', 'signature' => 'sig1=:AA==:']);
        self::assertSame(IdentityInterface::KIND_UNKNOWN, $identity->getKind());
    }

    public function testNothing(): void
    {
        $identity = $this->identify(['user-agent' => 'curl/8.4.0']);
        self::assertSame('unknown', $identity->getName());
        self::assertSame('unknown', $identity->getCategory());
    }
}
