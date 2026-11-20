<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Test\Unit\Model\Signature;

use Angeo\AiBots\Model\Signature\StructuredFields;
use PHPUnit\Framework\TestCase;

class StructuredFieldsTest extends TestCase
{
    private StructuredFields $fields;

    protected function setUp(): void
    {
        $this->fields = new StructuredFields();
    }

    public function testSignatureInputRoundTrip(): void
    {
        $header = 'sig1=("@authority" "signature-agent";key="sig1");created=1735689600;expires=1735693200;'
            . 'keyid="poqkLGiymh_W0uP6PZFw-dvez3QJT5SolqXBCW38r0U";alg="ed25519";tag="web-bot-auth"';
        $dictionary = $this->fields->parseDictionary($header);
        self::assertNotNull($dictionary);
        self::assertSame('inner', $dictionary['sig1']['type']);
        self::assertSame('sig1', $dictionary['sig1']['value'][1]['params']['key']['value']);
        self::assertSame(1735689600, $dictionary['sig1']['params']['created']['value']);
        self::assertSame(substr($header, 5), $this->fields->serializeMember($dictionary['sig1']));
    }

    public function testDictionaryWithParametersAndBytes(): void
    {
        $dictionary = $this->fields->parseDictionary('a="https://x.test";type=jwks_uri, b=:aGVsbG8=:, c, d=?0, e=1.5');
        self::assertNotNull($dictionary);
        self::assertSame('jwks_uri', $dictionary['a']['params']['type']['value']);
        self::assertSame('"https://x.test";type=jwks_uri', $this->fields->serializeMember($dictionary['a']));
        self::assertSame('hello', $dictionary['b']['value']);
        self::assertTrue($dictionary['c']['value']);
        self::assertFalse($dictionary['d']['value']);
        self::assertSame('1.5', $this->fields->serializeMember($dictionary['e']));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function broken(): array
    {
        return [
            'trailing comma' => ['a=1,'],
            'unclosed string' => ['a="x'],
            'upper-case key' => ['A=1'],
            'bad escape' => ['a="\\x"'],
            'unclosed inner list' => ['a=("x"'],
            'bad bytes' => ['a=:not base64:'],
            'control char' => ["a=\"x\x01\""],
        ];
    }

    /**
     * @dataProvider broken
     */
    public function testBrokenInputIsRejected(string $header): void
    {
        self::assertNull($this->fields->parseDictionary($header));
    }

    public function testLegacyBareString(): void
    {
        $item = $this->fields->parseItem('"https://chatgpt.com"');
        self::assertSame(['type' => 'string', 'value' => 'https://chatgpt.com', 'params' => []], $item);
        self::assertNull($this->fields->parseItem('"https://a" junk'));
    }

    public function testStringEscapesSerialize(): void
    {
        $dictionary = $this->fields->parseDictionary('a="say \\"hi\\" \\\\ now"');
        self::assertNotNull($dictionary);
        self::assertSame('say "hi" \\ now', $dictionary['a']['value']);
        self::assertSame('"say \\"hi\\" \\\\ now"', $this->fields->serializeMember($dictionary['a']));
    }

    public function testTooLongIsRejected(): void
    {
        self::assertNull($this->fields->parseDictionary('a="' . str_repeat('x', StructuredFields::MAX_LENGTH) . '"'));
    }
}
