<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Model\Signature;

/**
 * The Signature-Agent header (draft-ietf-webbotauth-httpsig-protocol-00).
 *
 *   Signature-Agent: sig1="https://chatgpt.com"                      directory (default)
 *   Signature-Agent: sig1="https://x.example/jwks.json";type=jwks_uri
 *   Signature-Agent: "https://chatgpt.com"                           legacy, accepted
 *
 * The member key is the signature label. A legacy bare string applies to
 * whichever signature covers the header; it is returned under LEGACY_KEY.
 */
class SignatureAgentHeader
{
    public const LEGACY_KEY = '*legacy';

    public const TYPE_DIRECTORY = 'directory';
    public const TYPE_JWKS_URI = 'jwks_uri';
    public const TYPE_CIMD = 'cimd';

    public function __construct(
        private readonly StructuredFields $fields
    ) {
    }

    /**
     * label => ['url' => string, 'type' => string, 'member' => parsed member]
     *
     * Members that are not strings are skipped. An unknown type is kept with
     * its name so the verifier can ignore it, as the draft requires.
     *
     * @return array<string, array{url: string, type: string, member: array<string, mixed>}>
     */
    public function parse(string $header): array
    {
        $header = trim($header);
        if ($header === '') {
            return [];
        }
        if ($header[0] === '"') {
            $item = $this->fields->parseItem($header);
            return $item !== null && $item['type'] === 'string'
                ? [self::LEGACY_KEY => ['url' => (string) $item['value'], 'type' => self::TYPE_DIRECTORY, 'member' => $item]]
                : [];
        }
        $dictionary = $this->fields->parseDictionary($header);
        if ($dictionary === null) {
            return [];
        }
        $result = [];
        foreach ($dictionary as $label => $member) {
            if ($member['type'] !== 'string') {
                continue;
            }
            $type = self::TYPE_DIRECTORY;
            if (isset($member['params']['type'])) {
                $param = $member['params']['type'];
                $type = in_array($param['type'], ['token', 'string'], true) ? (string) $param['value'] : '';
            }
            $result[(string) $label] = ['url' => (string) $member['value'], 'type' => $type, 'member' => $member];
        }
        return $result;
    }

    /**
     * Hosts named in the header, lower case, for identification.
     *
     * @return string[]
     */
    public function hosts(string $header): array
    {
        $hosts = [];
        foreach ($this->parse($header) as $entry) {
            $host = parse_url($entry['url'], PHP_URL_HOST);
            if (is_string($host) && $host !== '' && str_starts_with(strtolower($entry['url']), 'https://')) {
                $hosts[strtolower($host)] = true;
            }
        }
        return array_keys($hosts);
    }
}
