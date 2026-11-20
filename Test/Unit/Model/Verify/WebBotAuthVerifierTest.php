<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Test\Unit\Model\Verify;

use Angeo\AiBots\Api\Data\IdentityInterface;
use Angeo\AiBots\Api\Data\VerificationResultInterface as Result;
use Angeo\AiBots\Model\BotIdentifier;
use Angeo\AiBots\Model\IncomingRequest;
use Angeo\AiBots\Model\Signature\SignatureAgentHeader;
use Angeo\AiBots\Model\Signature\SignatureBase;
use Angeo\AiBots\Model\Signature\SignatureParser;
use Angeo\AiBots\Model\Signature\StructuredFields;
use Angeo\AiBots\Model\Verify\StoredLists;
use Angeo\AiBots\Model\Verify\WebBotAuthVerifier;
use Angeo\AiBots\Test\Unit\Model\BotRegistryTest;
use Angeo\AiBots\Test\Unit\Model\Signature\Rfc9421VectorTest;
use PHPUnit\Framework\TestCase;

class WebBotAuthVerifierTest extends TestCase
{
    private const NOW = 1793000000;
    private const KEYID = 'poqkLGiymh_W0uP6PZFw-dvez3QJT5SolqXBCW38r0U';
    private const DIRECTORY = 'https://chatgpt.com/.well-known/http-message-signatures-directory';
    private const CHROME = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/537.36 Chrome/141.0 Safari/537.36';

    /** @var array<string, array<string, string>|null> */
    private array $stored = [];

    private function verifier(): WebBotAuthVerifier
    {
        $fields = new StructuredFields();
        $lists = $this->createMock(StoredLists::class);
        $lists->method('keys')->willReturnCallback(fn (string $url): ?array => $this->stored[$url] ?? null);
        return new WebBotAuthVerifier(new SignatureParser($fields), new SignatureAgentHeader($fields), new SignatureBase($fields), $lists);
    }

    private function identify(IncomingRequest $request): IdentityInterface
    {
        return (new BotIdentifier(BotRegistryTest::registry(), new SignatureAgentHeader(new StructuredFields())))->identify($request);
    }

    /**
     * A request signed with the RFC 9421 Ed25519 test key.
     *
     * @param array<string, string> $over header overrides; "" removes a header
     */
    private function request(
        string $params = '',
        string $components = '"@authority" "signature-agent";key="sig1"',
        array $over = [],
        string $authority = 'shop.example',
        string $agent = 'sig1="https://chatgpt.com"',
        ?string $signedAuthority = null
    ): IncomingRequest {
        $params = $params !== '' ? $params
            : sprintf(';created=%d;expires=%d;keyid="%s";alg="ed25519";tag="web-bot-auth"', self::NOW - 10, self::NOW + 3600, self::KEYID);
        $input = 'sig1=(' . $components . ')' . $params;
        $headers = ['user-agent' => self::CHROME, 'signature-agent' => $agent, 'signature-input' => $input];
        $signing = new IncomingRequest('GET', 'https', $signedAuthority ?? $authority, '/p.html', $headers + ['signature' => 'sig1=:AAAA:'], '203.0.113.9');
        $fields = new StructuredFields();
        $signature = (new SignatureParser($fields))->parse($input, 'sig1=:AAAA:')[0];
        $base = (string) (new SignatureBase($fields))->build($signing, $signature);
        $headers['signature'] = 'sig1=:' . base64_encode(sodium_crypto_sign_detached($base, Rfc9421VectorTest::secretKey())) . ':';
        foreach ($over as $name => $value) {
            if ($value === '') {
                unset($headers[$name]);
            } else {
                $headers[$name] = $value;
            }
        }
        return new IncomingRequest('GET', 'https', $authority, '/p.html', $headers, '203.0.113.9');
    }

    private function storeKeys(): void
    {
        $this->stored[self::DIRECTORY] = [self::KEYID => Rfc9421VectorTest::publicKey()];
    }

    public function testBaseUsesTheDictionaryMember(): void
    {
        $fields = new StructuredFields();
        $request = $this->request();
        $signature = (new SignatureParser($fields))->parse((string) $request->getHeader('signature-input'), (string) $request->getHeader('signature'))[0];
        self::assertSame(
            "\"@authority\": shop.example\n"
            . "\"signature-agent\";key=\"sig1\": \"https://chatgpt.com\"\n"
            . "\"@signature-params\": (\"@authority\" \"signature-agent\";key=\"sig1\");created=1792999990;expires=1793003600;keyid=\"" . self::KEYID . "\";alg=\"ed25519\";tag=\"web-bot-auth\"",
            (new SignatureBase($fields))->build($request, $signature)
        );
    }

    public function testValidSignatureIsVerified(): void
    {
        $this->storeKeys();
        $request = $this->request();
        $identity = $this->identify($request);
        self::assertSame('chatgpt_agent', $identity->getName());
        self::assertSame(IdentityInterface::KIND_SIGNED_AGENT, $identity->getKind());

        $result = $this->verifier()->verify($identity, $request, null, self::NOW);
        self::assertSame(Result::STATE_VERIFIED, $result->getState(), $result->getReason());
        self::assertSame('https://chatgpt.com', $result->getDetails()['origin']);
    }

    public function testLegacyBareStringIsAccepted(): void
    {
        $this->storeKeys();
        $request = $this->request('', '"@authority" "signature-agent"', [], 'shop.example', '"https://chatgpt.com"');
        $result = $this->verifier()->verify($this->identify($request), $request, null, self::NOW);
        self::assertSame(Result::STATE_VERIFIED, $result->getState(), $result->getReason());
    }

    public function testReplayOnAnotherHostIsInvalid(): void
    {
        $this->storeKeys();
        $request = $this->request('', '"@authority" "signature-agent";key="sig1"', [], 'other-shop.example', 'sig1="https://chatgpt.com"', 'shop.example');
        $result = $this->verifier()->verify($this->identify($request), $request, null, self::NOW);
        self::assertSame(Result::STATE_INVALID, $result->getState());
    }

    public function testSwappedSignatureAgentIsInvalid(): void
    {
        $this->storeKeys();
        // Signed for chatgpt.com, header changed afterwards to claim Google's agent.
        $request = $this->request('', '"@authority" "signature-agent";key="sig1"', ['signature-agent' => 'sig1="https://agent.bot.goog"']);
        $identity = $this->identify($request);
        self::assertSame('google_agent', $identity->getName());
        $this->stored['https://agent.bot.goog/.well-known/http-message-signatures-directory'] = [self::KEYID => Rfc9421VectorTest::publicKey()];
        $result = $this->verifier()->verify($identity, $request, null, self::NOW);
        self::assertSame(Result::STATE_INVALID, $result->getState());
    }

    public function testBotSigningWithAnotherOriginIsInvalid(): void
    {
        $this->stored['https://evil.example/.well-known/http-message-signatures-directory'] = [self::KEYID => Rfc9421VectorTest::publicKey()];
        $request = $this->request('', '"@authority" "signature-agent";key="sig1"', ['user-agent' => 'ChatGPT-User/1.0'], 'shop.example', 'sig1="https://evil.example"');
        $identity = $this->identify($request);
        self::assertSame('chatgpt_user', $identity->getName());
        $result = $this->verifier()->verify($identity, $request, null, self::NOW);
        self::assertSame(Result::STATE_INVALID, $result->getState());
        self::assertStringContainsString('does not sign with https://evil.example', $result->getReason());
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function badSignatures(): array
    {
        $k = self::KEYID;
        return [
            'expired' => [sprintf(';created=%d;expires=%d;keyid="%s";tag="web-bot-auth"', self::NOW - 7200, self::NOW - 3600, $k), '"@authority" "signature-agent";key="sig1"', Result::STATE_INVALID],
            'from the future' => [sprintf(';created=%d;expires=%d;keyid="%s";tag="web-bot-auth"', self::NOW + 600, self::NOW + 900, $k), '"@authority" "signature-agent";key="sig1"', Result::STATE_INVALID],
            'window over 24 hours' => [sprintf(';created=%d;expires=%d;keyid="%s";tag="web-bot-auth"', self::NOW - 10, self::NOW + 90000, $k), '"@authority" "signature-agent";key="sig1"', Result::STATE_INVALID],
            'no keyid' => [sprintf(';created=%d;expires=%d;tag="web-bot-auth"', self::NOW - 10, self::NOW + 60), '"@authority" "signature-agent";key="sig1"', Result::STATE_INVALID],
            'no expires' => [sprintf(';created=%d;keyid="%s";tag="web-bot-auth"', self::NOW - 10, $k), '"@authority" "signature-agent";key="sig1"', Result::STATE_INVALID],
            'agent not covered' => [sprintf(';created=%d;expires=%d;keyid="%s";tag="web-bot-auth"', self::NOW - 10, self::NOW + 60, $k), '"@authority"', Result::STATE_INVALID],
            'authority not covered' => [sprintf(';created=%d;expires=%d;keyid="%s";tag="web-bot-auth"', self::NOW - 10, self::NOW + 60, $k), '"@path" "signature-agent";key="sig1"', Result::STATE_INVALID],
            'no web-bot-auth tag' => [sprintf(';created=%d;expires=%d;keyid="%s"', self::NOW - 10, self::NOW + 60, $k), '"@authority" "signature-agent";key="sig1"', Result::STATE_UNVERIFIED],
            'other algorithm' => [sprintf(';created=%d;expires=%d;keyid="%s";alg="rsa-pss-sha512";tag="web-bot-auth"', self::NOW - 10, self::NOW + 60, $k), '"@authority" "signature-agent";key="sig1"', Result::STATE_UNVERIFIED],
            'unknown key' => [sprintf(';created=%d;expires=%d;keyid="other";tag="web-bot-auth"', self::NOW - 10, self::NOW + 60), '"@authority" "signature-agent";key="sig1"', Result::STATE_UNVERIFIED],
            'unsupported component' => [sprintf(';created=%d;expires=%d;keyid="%s";tag="web-bot-auth"', self::NOW - 10, self::NOW + 60, $k), '"@authority" "signature-agent";key="sig1" "@query-param";name="a"', Result::STATE_UNVERIFIED],
        ];
    }

    /**
     * @dataProvider badSignatures
     */
    public function testBadSignatures(string $params, string $components, string $state): void
    {
        $this->storeKeys();
        $request = $this->request($params, $components);
        $result = $this->verifier()->verify($this->identify($request), $request, null, self::NOW);
        self::assertSame($state, $result->getState(), $result->getReason());
    }

    public function testTamperedSignatureIsInvalid(): void
    {
        $this->storeKeys();
        $request = $this->request();
        $bytes = (string) base64_decode(substr((string) $request->getHeader('signature'), 6, -1));
        $bytes[0] = chr(ord($bytes[0]) ^ 1);
        $request = $this->request('', '"@authority" "signature-agent";key="sig1"', ['signature' => 'sig1=:' . base64_encode($bytes) . ':']);
        $result = $this->verifier()->verify($this->identify($request), $request, null, self::NOW);
        self::assertSame(Result::STATE_INVALID, $result->getState());
    }

    public function testKeysNotDownloadedIsUnverifiedAndNeverFetched(): void
    {
        $request = $this->request();
        $result = $this->verifier()->verify($this->identify($request), $request, null, self::NOW);
        self::assertSame(Result::STATE_UNVERIFIED, $result->getState());
        self::assertSame(self::DIRECTORY, $result->getDetails()['directory']);
    }

    public function testCliKeyLoaderIsUsedWhenGiven(): void
    {
        $request = $this->request('', '"@authority" "signature-agent";key="sig1"', [], 'shop.example', 'sig1="https://new-agent.example"');
        $identity = $this->identify($request);
        self::assertSame('new-agent.example', $identity->getName());
        $asked = [];
        $result = $this->verifier()->verify($identity, $request, function (string $url) use (&$asked): array {
            $asked[] = $url;
            return [self::KEYID => Rfc9421VectorTest::publicKey()];
        }, self::NOW);
        self::assertSame(['https://new-agent.example/.well-known/http-message-signatures-directory'], $asked);
        self::assertSame(Result::STATE_VERIFIED, $result->getState());
    }

    public function testJwksUriTypeUsesTheUrlAsGiven(): void
    {
        $url = 'https://new-agent.example/keys.json';
        $request = $this->request('', '"@authority" "signature-agent";key="sig1"', [], 'shop.example', 'sig1="' . $url . '";type=jwks_uri');
        $result = $this->verifier()->verify($this->identify($request), $request, static fn (string $u): ?array => $u === $url ? [self::KEYID => Rfc9421VectorTest::publicKey()] : null, self::NOW);
        self::assertSame(Result::STATE_VERIFIED, $result->getState(), $result->getReason());
    }

    public function testUnknownTypeIsIgnored(): void
    {
        $request = $this->request('', '"@authority" "signature-agent";key="sig1"', [], 'shop.example', 'sig1="https://x.example/card";type=cimd');
        $result = $this->verifier()->verify($this->identify($request), $request, static fn (): array => [self::KEYID => Rfc9421VectorTest::publicKey()], self::NOW);
        self::assertSame(Result::STATE_UNVERIFIED, $result->getState());
    }

    public function testDirectoryTypeWithAPathIsIgnored(): void
    {
        self::assertNull(WebBotAuthVerifier::directoryUrl(['url' => 'https://x.example/some/path', 'type' => 'directory']));
        self::assertNull(WebBotAuthVerifier::directoryUrl(['url' => 'http://x.example', 'type' => 'directory']));
        self::assertSame('https://x.example/.well-known/http-message-signatures-directory', WebBotAuthVerifier::directoryUrl(['url' => 'https://x.example/', 'type' => 'directory']));
    }
}
