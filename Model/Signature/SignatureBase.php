<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Model\Signature;

use Angeo\AiBots\Api\Data\IncomingRequestInterface;

/**
 * Rebuilds the RFC 9421 signature base (section 2.5) for one signature.
 *
 * Supported: @method, @scheme, @authority, @target-uri, @path, @query, header
 * fields, and header fields with the "key" (dictionary member) or "sf"
 * parameter. Anything else (bs, req, tr, @query-param, @request-target)
 * returns null: a signature this code cannot rebuild exactly must never be
 * treated as valid.
 */
class SignatureBase
{
    public function __construct(
        private readonly StructuredFields $fields
    ) {
    }

    /**
     * The base, or null when a covered component cannot be reproduced.
     */
    public function build(IncomingRequestInterface $request, HttpSignature $signature): ?string
    {
        $lines = [];
        $seen = [];
        foreach ($signature->definition['value'] as $item) {
            $identifier = $this->fields->serializeMember($item);
            if (isset($seen[$identifier])) {
                return null; // RFC 9421: a component may appear only once
            }
            $seen[$identifier] = true;
            $value = $this->value($request, strtolower((string) $item['value']), $item['params'] ?? []);
            if ($value === null) {
                return null;
            }
            $lines[] = $identifier . ': ' . $value;
        }
        $lines[] = '"@signature-params": ' . $this->fields->serializeMember($signature->definition);
        return implode("\n", $lines);
    }

    /**
     * @param array<string, array<string, mixed>> $params
     */
    private function value(IncomingRequestInterface $request, string $name, array $params): ?string
    {
        if (str_starts_with($name, '@')) {
            return $params === [] ? $this->derived($request, $name) : null;
        }
        $header = $request->getHeader($name);
        if ($header === null) {
            return null;
        }
        $names = array_keys($params);
        if ($names === []) {
            return trim((string) preg_replace('/\s*\r?\n\s*/', ' ', $header));
        }
        if ($names === ['key']) {
            $dictionary = $this->fields->parseDictionary($header);
            $member = $dictionary[(string) $params['key']['value']] ?? null;
            return $member === null ? null : $this->fields->serializeMember($member);
        }
        if ($names === ['sf']) {
            $dictionary = $this->fields->parseDictionary($header);
            if ($dictionary === null) {
                return null;
            }
            $parts = [];
            foreach ($dictionary as $key => $member) {
                $parts[] = $member['type'] === 'boolean' && $member['value'] === true
                    ? $key . $this->fields->serializeParams($member['params'])
                    : $key . '=' . $this->fields->serializeMember($member);
            }
            return implode(', ', $parts);
        }
        return null;
    }

    private function derived(IncomingRequestInterface $request, string $name): ?string
    {
        $target = $request->getPathAndQuery();
        $queryPos = strpos($target, '?');
        $path = $queryPos === false ? $target : substr($target, 0, $queryPos);
        return match ($name) {
            '@method' => $request->getMethod(),
            '@scheme' => $request->getScheme(),
            '@authority' => $this->authority($request),
            '@target-uri' => $request->getScheme() . '://' . $this->authority($request) . $target,
            '@path' => $path === '' ? '/' : $path,
            '@query' => $queryPos === false ? '?' : substr($target, $queryPos),
            default => null,
        };
    }

    /**
     * Host, with the port only when it is not the default for the scheme.
     */
    private function authority(IncomingRequestInterface $request): string
    {
        $authority = $request->getAuthority();
        $default = $request->getScheme() === 'https' ? ':443' : ':80';
        return str_ends_with($authority, $default) ? substr($authority, 0, -strlen($default)) : $authority;
    }
}
