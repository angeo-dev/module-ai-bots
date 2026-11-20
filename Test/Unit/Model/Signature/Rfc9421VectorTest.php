<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Test\Unit\Model\Signature;

use Angeo\AiBots\Model\IncomingRequest;
use Angeo\AiBots\Model\Signature\JwksParser;
use Angeo\AiBots\Model\Signature\SignatureBase;
use Angeo\AiBots\Model\Signature\SignatureParser;
use Angeo\AiBots\Model\Signature\StructuredFields;
use PHPUnit\Framework\TestCase;

/**
 * Published test vectors, so the parser and base builder are checked
 * against the standards and not only against this module's own output.
 *
 *  - RFC 9421 appendix B.2.6 "Signing a Request Using ed25519", with the
 *    test key of appendix B.1.4;
 *  - RFC 8037 appendix A.3 JWK thumbprint;
 *  - draft-ietf-webbotauth-httpsig-protocol-00 appendix E.2: the keyid of
 *    the RFC 9421 Ed25519 test key.
 */
class Rfc9421VectorTest extends TestCase
{
    /** RFC 9421 B.1.4 test-key-ed25519, public part. */
    public const TEST_KEY_X = 'JrQLj5P_89iXES9-vFgrIy29clF9CC_oPPsw3c5D0bs';

    /** RFC 9421 B.1.4 test-key-ed25519, PKCS#8 private key (test key, published in the RFC). */
    public const TEST_KEY_PKCS8 = 'MC4CAQAwBQYDK2VwBCIEIJ+DYvh6SEqVTm50DFtMDoQikTmiCqirVv9mWG9qfSnF';

    public static function secretKey(): string
    {
        $seed = substr((string) base64_decode(self::TEST_KEY_PKCS8, true), -32);
        return sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($seed));
    }

    public static function publicKey(): string
    {
        return (string) base64_decode(strtr(self::TEST_KEY_X, '-_', '+/') . '=', true);
    }

    public function testB26SignatureBaseAndSignature(): void
    {
        $request = new IncomingRequest('POST', 'https', 'example.com', '/foo?param=Value&Pet=dog', [
            'Host' => 'example.com',
            'Date' => 'Tue, 20 Apr 2021 02:07:55 GMT',
            'Content-Type' => 'application/json',
            'Content-Length' => '18',
            'Signature-Input' => 'sig-b26=("date" "@method" "@path" "@authority" "content-type" "content-length");created=1618884473;keyid="test-key-ed25519"',
            'Signature' => 'sig-b26=:wqcAqbmYJ2ji2glfAMaRy4gruYYnx2nEFN2HN6jrnDnQCK1u02Gb04v9EDgwUPiu4A0w6vuQv5lIp5WPpBKRCw==:',
        ], '');
        $fields = new StructuredFields();
        $signatures = (new SignatureParser($fields))->parse(
            (string) $request->getHeader('signature-input'),
            (string) $request->getHeader('signature')
        );
        self::assertCount(1, $signatures);

        $base = (new SignatureBase($fields))->build($request, $signatures[0]);
        self::assertSame(
            "\"date\": Tue, 20 Apr 2021 02:07:55 GMT\n"
            . "\"@method\": POST\n"
            . "\"@path\": /foo\n"
            . "\"@authority\": example.com\n"
            . "\"content-type\": application/json\n"
            . "\"content-length\": 18\n"
            . "\"@signature-params\": (\"date\" \"@method\" \"@path\" \"@authority\" \"content-type\" \"content-length\");created=1618884473;keyid=\"test-key-ed25519\"",
            $base
        );
        self::assertTrue(sodium_crypto_sign_verify_detached($signatures[0]->signature, (string) $base, self::publicKey()));
    }

    public function testTestKeyMatchesItsPublicPart(): void
    {
        self::assertSame(self::publicKey(), sodium_crypto_sign_publickey_from_secretkey(self::secretKey()));
    }

    public function testThumbprints(): void
    {
        // RFC 8037 A.3
        self::assertSame('kPrK_qmxVWaYVA9wwBF6Iuo3vVzz7TxHCTwXBygrS4k', JwksParser::thumbprint('11qYAYKxCrfVS_7TyWQHOg7hcvPapiMlrwIaaPcHURo'));
        // Web Bot Auth draft E.2 keyid for the RFC 9421 Ed25519 test key
        self::assertSame('poqkLGiymh_W0uP6PZFw-dvez3QJT5SolqXBCW38r0U', JwksParser::thumbprint(self::TEST_KEY_X));
    }

    public function testJwksParserKeysByThumbprint(): void
    {
        $keys = (new JwksParser())->parse((string) json_encode(['keys' => [
            ['kty' => 'OKP', 'crv' => 'Ed25519', 'kid' => 'any-label', 'x' => self::TEST_KEY_X],
            ['kty' => 'RSA', 'n' => 'x', 'e' => 'AQAB'],
        ]]));
        self::assertSame(['poqkLGiymh_W0uP6PZFw-dvez3QJT5SolqXBCW38r0U' => self::publicKey()], $keys);
    }

    public function testJwksWithPrivateKeyIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        (new JwksParser())->parse((string) json_encode(['keys' => [
            ['kty' => 'OKP', 'crv' => 'Ed25519', 'x' => self::TEST_KEY_X, 'd' => 'secret'],
        ]]));
    }
}
