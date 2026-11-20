<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Model\Signature;

/**
 * RFC 8941 Structured Field Values: the subset HTTP Message Signatures need.
 *
 * Parses Dictionaries whose members are Items or Inner Lists, with
 * Parameters, and serializes them back in canonical form (RFC 8941 section 4.1),
 * which RFC 9421 requires for the signature base.
 *
 * Representation:
 *   item       ['type' => 'string'|'token'|'integer'|'decimal'|'bytes'|'boolean',
 *               'value' => mixed, 'params' => array<string, item-without-params>]
 *   inner list ['type' => 'inner', 'value' => list<item>, 'params' => …]
 *
 * Every parse method returns null on any syntax error: a header that does not
 * parse is treated as absent, never guessed.
 */
class StructuredFields
{
    /** Longest header value accepted. */
    public const MAX_LENGTH = 8192;

    /**
     * @return array<string, array<string, mixed>>|null
     */
    public function parseDictionary(string $input): ?array
    {
        if (strlen($input) > self::MAX_LENGTH) {
            return null;
        }
        $pos = 0;
        $len = strlen($input);
        $this->skipSpaces($input, $pos);
        $result = [];
        while ($pos < $len) {
            $key = $this->parseKey($input, $pos);
            if ($key === null) {
                return null;
            }
            if ($pos < $len && $input[$pos] === '=') {
                $pos++;
                $member = $this->parseItemOrInnerList($input, $pos);
                if ($member === null) {
                    return null;
                }
            } else {
                $params = $this->parseParameters($input, $pos);
                if ($params === null) {
                    return null;
                }
                $member = ['type' => 'boolean', 'value' => true, 'params' => $params];
            }
            $result[$key] = $member;
            $this->skipOws($input, $pos);
            if ($pos >= $len) {
                return $result;
            }
            if ($input[$pos] !== ',') {
                return null;
            }
            $pos++;
            $this->skipOws($input, $pos);
            if ($pos >= $len) {
                return null; // trailing comma
            }
        }
        return $result;
    }

    /**
     * A single Item (used for the legacy bare-string Signature-Agent).
     *
     * @return array<string, mixed>|null
     */
    public function parseItem(string $input): ?array
    {
        if (strlen($input) > self::MAX_LENGTH) {
            return null;
        }
        $pos = 0;
        $this->skipSpaces($input, $pos);
        $item = $this->parseBareItemWithParams($input, $pos);
        $this->skipSpaces($input, $pos);
        return $item !== null && $pos === strlen($input) ? $item : null;
    }

    /**
     * Canonical serialization of an Item or Inner List (with its parameters).
     *
     * @param array<string, mixed> $member
     */
    public function serializeMember(array $member): string
    {
        if ($member['type'] === 'inner') {
            $parts = [];
            foreach ($member['value'] as $item) {
                $parts[] = $this->serializeMember($item);
            }
            return '(' . implode(' ', $parts) . ')' . $this->serializeParams($member['params'] ?? []);
        }
        return $this->serializeBare($member) . $this->serializeParams($member['params'] ?? []);
    }

    /**
     * Canonical serialization of parameters.
     *
     * @param array<string, array<string, mixed>> $params
     */
    public function serializeParams(array $params): string
    {
        $out = '';
        foreach ($params as $name => $value) {
            $out .= ';' . $name;
            if (!($value['type'] === 'boolean' && $value['value'] === true)) {
                $out .= '=' . $this->serializeBare($value);
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function serializeBare(array $item): string
    {
        return match ($item['type']) {
            'string' => '"' . addcslashes((string) $item['value'], '"\\') . '"',
            'token' => (string) $item['value'],
            'integer' => (string) (int) $item['value'],
            'decimal' => $this->serializeDecimal((float) $item['value']),
            'bytes' => ':' . base64_encode((string) $item['value']) . ':',
            'boolean' => $item['value'] ? '?1' : '?0',
            default => '',
        };
    }

    /**
     * At most three fraction digits, at least one (RFC 8941 section 4.1.5).
     */
    private function serializeDecimal(float $value): string
    {
        $text = rtrim(number_format($value, 3, '.', ''), '0');
        return str_ends_with($text, '.') ? $text . '0' : $text;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseItemOrInnerList(string $in, int &$pos): ?array
    {
        if ($pos < strlen($in) && $in[$pos] === '(') {
            return $this->parseInnerList($in, $pos);
        }
        return $this->parseBareItemWithParams($in, $pos);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseInnerList(string $in, int &$pos): ?array
    {
        $pos++; // (
        $len = strlen($in);
        $items = [];
        while ($pos < $len) {
            $this->skipSpaces($in, $pos);
            if ($pos < $len && $in[$pos] === ')') {
                $pos++;
                $params = $this->parseParameters($in, $pos);
                return $params === null ? null : ['type' => 'inner', 'value' => $items, 'params' => $params];
            }
            $item = $this->parseBareItemWithParams($in, $pos);
            if ($item === null) {
                return null;
            }
            $items[] = $item;
            if ($pos < $len && $in[$pos] !== ' ' && $in[$pos] !== ')') {
                return null;
            }
        }
        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseBareItemWithParams(string $in, int &$pos): ?array
    {
        $bare = $this->parseBareItem($in, $pos);
        if ($bare === null) {
            return null;
        }
        $params = $this->parseParameters($in, $pos);
        if ($params === null) {
            return null;
        }
        $bare['params'] = $params;
        return $bare;
    }

    /**
     * @return array<string, array<string, mixed>>|null
     */
    private function parseParameters(string $in, int &$pos): ?array
    {
        $params = [];
        $len = strlen($in);
        while ($pos < $len && $in[$pos] === ';') {
            $pos++;
            $this->skipSpaces($in, $pos);
            $key = $this->parseKey($in, $pos);
            if ($key === null) {
                return null;
            }
            $value = ['type' => 'boolean', 'value' => true];
            if ($pos < $len && $in[$pos] === '=') {
                $pos++;
                $value = $this->parseBareItem($in, $pos);
                if ($value === null) {
                    return null;
                }
            }
            $params[$key] = $value;
        }
        return $params;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseBareItem(string $in, int &$pos): ?array
    {
        if ($pos >= strlen($in)) {
            return null;
        }
        $c = $in[$pos];
        if ($c === '"') {
            return $this->parseString($in, $pos);
        }
        if ($c === ':') {
            return $this->parseBytes($in, $pos);
        }
        if ($c === '?') {
            $flag = substr($in, $pos + 1, 1);
            if ($flag !== '0' && $flag !== '1') {
                return null;
            }
            $pos += 2;
            return ['type' => 'boolean', 'value' => $flag === '1'];
        }
        if ($c === '-' || ctype_digit($c)) {
            return $this->parseNumber($in, $pos);
        }
        if ($c === '*' || ctype_alpha($c)) {
            if (!preg_match('/\G[A-Za-z*][A-Za-z0-9:\/!#$%&\'*+\-.^_`|~]*/', $in, $m, 0, $pos)) {
                return null;
            }
            $pos += strlen($m[0]);
            return ['type' => 'token', 'value' => $m[0]];
        }
        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseString(string $in, int &$pos): ?array
    {
        $pos++;
        $len = strlen($in);
        $out = '';
        while ($pos < $len) {
            $c = $in[$pos++];
            if ($c === '\\') {
                if ($pos >= $len || ($in[$pos] !== '"' && $in[$pos] !== '\\')) {
                    return null;
                }
                $out .= $in[$pos++];
                continue;
            }
            if ($c === '"') {
                return ['type' => 'string', 'value' => $out];
            }
            $ord = ord($c);
            if ($ord < 0x20 || $ord > 0x7e) {
                return null;
            }
            $out .= $c;
        }
        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseBytes(string $in, int &$pos): ?array
    {
        $end = strpos($in, ':', $pos + 1);
        if ($end === false) {
            return null;
        }
        $b64 = substr($in, $pos + 1, $end - $pos - 1);
        if (!preg_match('/^[A-Za-z0-9+\/]*={0,2}\z/', $b64)) {
            return null;
        }
        $decoded = base64_decode($b64, true);
        if ($decoded === false) {
            return null;
        }
        $pos = $end + 1;
        return ['type' => 'bytes', 'value' => $decoded];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseNumber(string $in, int &$pos): ?array
    {
        if (!preg_match('/\G-?\d{1,15}(\.\d{1,3})?/', $in, $m, 0, $pos)) {
            return null;
        }
        $pos += strlen($m[0]);
        if (isset($m[1])) {
            return ['type' => 'decimal', 'value' => (float) $m[0]];
        }
        return ['type' => 'integer', 'value' => (int) $m[0]];
    }

    private function parseKey(string $in, int &$pos): ?string
    {
        if (!preg_match('/\G[a-z*][a-z0-9_\-.*]*/', $in, $m, 0, $pos)) {
            return null;
        }
        $pos += strlen($m[0]);
        return $m[0];
    }

    private function skipSpaces(string $in, int &$pos): void
    {
        while ($pos < strlen($in) && $in[$pos] === ' ') {
            $pos++;
        }
    }

    private function skipOws(string $in, int &$pos): void
    {
        while ($pos < strlen($in) && ($in[$pos] === ' ' || $in[$pos] === "\t")) {
            $pos++;
        }
    }
}
