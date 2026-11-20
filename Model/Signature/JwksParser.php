<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Model\Signature;

/**
 * Reads a JWK Set and keeps the Ed25519 public keys, keyed by their JWK
 * SHA-256 thumbprint (RFC 7638, with RFC 8037 for OKP keys).
 *
 * Web Bot Auth requires keyid to be that thumbprint, so the verifier never
 * trusts the "kid" label of a key. Private key members ("d") are refused:
 * a directory that publishes one is broken, and its keys are not used.
 */
class JwksParser
{
    public const MAX_KEYS = 20;

    /**
     * thumbprint => raw 32-byte public key.
     *
     * @return array<string, string>
     * @throws \RuntimeException when the document is not a usable JWK Set
     */
    public function parse(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !is_array($data['keys'] ?? null)) {
            throw new \RuntimeException('no "keys" array');
        }
        $now = time();
        $keys = [];
        foreach (array_slice($data['keys'], 0, self::MAX_KEYS) as $jwk) {
            if (!is_array($jwk) || ($jwk['kty'] ?? '') !== 'OKP' || ($jwk['crv'] ?? '') !== 'Ed25519') {
                continue;
            }
            if (isset($jwk['d'])) {
                throw new \RuntimeException('directory publishes a private key');
            }
            if (isset($jwk['use']) && $jwk['use'] !== 'sig') {
                continue;
            }
            if ((isset($jwk['nbf']) && is_numeric($jwk['nbf']) && $now < (int) $jwk['nbf'])
                || (isset($jwk['exp']) && is_numeric($jwk['exp']) && $now > (int) $jwk['exp'])
            ) {
                continue;
            }
            $x = $jwk['x'] ?? null;
            $raw = is_string($x) ? self::base64UrlDecode($x) : null;
            if ($raw === null || strlen($raw) !== 32) {
                continue;
            }
            $keys[self::thumbprint($x)] = $raw;
        }
        if ($keys === []) {
            throw new \RuntimeException('no usable Ed25519 key');
        }
        return $keys;
    }

    /**
     * RFC 8037 appendix A.3: SHA-256 over {"crv":"Ed25519","kty":"OKP","x":"…"}.
     */
    public static function thumbprint(string $x): string
    {
        $canonical = '{"crv":"Ed25519","kty":"OKP","x":"' . $x . '"}';
        return rtrim(strtr(base64_encode(hash('sha256', $canonical, true)), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_-]+\z/', $value)) {
            return null;
        }
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        return $decoded === false ? null : $decoded;
    }
}
