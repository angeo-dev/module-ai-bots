<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Model\Verify;

use Angeo\AiBots\Api\Data\IdentityInterface;
use Angeo\AiBots\Api\Data\IncomingRequestInterface;
use Angeo\AiBots\Api\Data\VerificationResultInterface as Result;
use Angeo\AiBots\Model\Bot;
use Angeo\AiBots\Model\Signature\HttpSignature;
use Angeo\AiBots\Model\Signature\SignatureAgentHeader;
use Angeo\AiBots\Model\Signature\SignatureBase;
use Angeo\AiBots\Model\Signature\SignatureParser;

/**
 * Web Bot Auth: HTTP Message Signatures for bots
 * (draft-ietf-webbotauth-httpsig-protocol-00, September 2026; RFC 9421).
 *
 * What is checked, for each signature tagged "web-bot-auth":
 *  - Signature-Agent has a member for the signature's label (dictionary
 *    form), or is a legacy bare string; type "directory" (an origin) or
 *    "jwks_uri" (a URL). Other types are ignored, as the draft requires;
 *  - the signature covers that member and @authority or @target-uri;
 *  - created, expires and keyid are present; the window is at most 24 hours
 *    and the signature is not expired (60 s clock skew);
 *  - for a known bot, the signing origin is one the bot is listed with;
 *  - keyid is the JWK thumbprint of an Ed25519 key in the directory, and the
 *    Ed25519 signature over the rebuilt base is valid.
 *
 * Keys come only from what the daily refresh stored (or, in the CLI, from a
 * one-off download). A storefront request never triggers a fetch, so a
 * signed request cannot make the store call an address of the sender's
 * choice.
 *
 * Outcomes: "verified"; "invalid" when a check fails that the signer
 * controls; "unverified" when it cannot be checked here (no ext-sodium,
 * other algorithm, key not downloaded, component not supported).
 */
class WebBotAuthVerifier
{
    public const TAG = 'web-bot-auth';
    public const WELL_KNOWN = '/.well-known/http-message-signatures-directory';
    public const CLOCK_SKEW = 60;
    public const MAX_WINDOW = 86400;

    public function __construct(
        private readonly SignatureParser $parser,
        private readonly SignatureAgentHeader $agentHeader,
        private readonly SignatureBase $base,
        private readonly StoredLists $lists
    ) {
    }

    /**
     * Key directory URL for a Signature-Agent entry, or null when the entry
     * cannot be used.
     *
     * @param array{url: string, type: string} $entry
     */
    public static function directoryUrl(array $entry): ?string
    {
        if ($entry['type'] === SignatureAgentHeader::TYPE_DIRECTORY) {
            $origin = Bot::origin($entry['url']);
            return $origin === null ? null : $origin . self::WELL_KNOWN;
        }
        if ($entry['type'] === SignatureAgentHeader::TYPE_JWKS_URI) {
            return str_starts_with(strtolower($entry['url']), 'https://')
                && filter_var($entry['url'], FILTER_VALIDATE_URL) !== false ? $entry['url'] : null;
        }
        return null;
    }

    /**
     * @param callable(string): ?array<string, string>|null $keys directory URL => thumbprint => raw key;
     *        null = the keys stored by the refresh job
     */
    public function verify(
        IdentityInterface $identity,
        IncomingRequestInterface $request,
        ?callable $keys = null,
        ?int $now = null
    ): Result {
        $method = Result::METHOD_WEB_BOT_AUTH;
        $now ??= time();
        $keys ??= fn (string $url): ?array => $this->lists->keys($url);

        $signatures = array_values(array_filter(
            $this->parser->parse(
                (string) $request->getHeader('signature-input'),
                (string) $request->getHeader('signature')
            ),
            static fn (HttpSignature $s): bool => $s->param('tag') === self::TAG
        ));
        if ($signatures === []) {
            return VerificationResult::of(Result::STATE_UNVERIFIED, $method, 'No signature tagged "web-bot-auth".');
        }
        $agents = $this->agentHeader->parse((string) $request->getHeader('signature-agent'));

        $last = VerificationResult::of(Result::STATE_UNVERIFIED, $method, 'No signature could be checked.');
        foreach ($signatures as $signature) {
            $result = $this->verifyOne($identity, $request, $signature, $agents, $keys, $now);
            if ($result->isVerified()) {
                return $result;
            }
            if ($result->isInvalid() || $last->getState() !== Result::STATE_INVALID) {
                $last = $result;
            }
        }
        return $last;
    }

    /**
     * @param array<string, array{url: string, type: string, member: array<string, mixed>}> $agents
     */
    private function verifyOne(
        IdentityInterface $identity,
        IncomingRequestInterface $request,
        HttpSignature $signature,
        array $agents,
        callable $keys,
        int $now
    ): Result {
        $method = Result::METHOD_WEB_BOT_AUTH;
        $legacy = !isset($agents[$signature->label]) && isset($agents[SignatureAgentHeader::LEGACY_KEY]);
        $entry = $agents[$signature->label] ?? $agents[SignatureAgentHeader::LEGACY_KEY] ?? null;
        if ($entry === null) {
            return $this->invalid('Signature-Agent has no entry for signature "' . $signature->label . '".');
        }
        if (!$signature->covers('signature-agent', $legacy ? null : $signature->label)) {
            return $this->invalid('The signature does not cover the Signature-Agent header: the identity claim is unsigned.');
        }
        if (!$signature->covers('@authority') && !$signature->covers('@target-uri')) {
            return $this->invalid('The signature covers neither @authority nor @target-uri, so it could be replayed on any site.');
        }

        $created = $signature->intParam('created');
        $expires = $signature->intParam('expires');
        $keyId = $signature->param('keyid');
        if ($created === null || $expires === null || $keyId === null) {
            return $this->invalid('The signature lacks created, expires or keyid.');
        }
        if ($created > $now + self::CLOCK_SKEW) {
            return $this->invalid('The signature is created in the future.');
        }
        if ($expires < $now - self::CLOCK_SKEW) {
            return $this->invalid('The signature has expired.');
        }
        if ($expires - $created > self::MAX_WINDOW) {
            return $this->invalid('The signature is valid for more than 24 hours.');
        }

        $alg = $signature->param('alg');
        if ($alg !== null && $alg !== 'ed25519') {
            return VerificationResult::of(Result::STATE_UNVERIFIED, $method, sprintf('Algorithm "%s" is not supported here (only ed25519).', $alg));
        }
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return VerificationResult::of(Result::STATE_UNVERIFIED, $method, 'PHP ext-sodium is missing, so Ed25519 cannot be checked.');
        }

        $directory = self::directoryUrl($entry);
        if ($directory === null) {
            return VerificationResult::of(
                Result::STATE_UNVERIFIED,
                $method,
                sprintf('Signature-Agent type "%s" is not supported, or its URL is not usable.', $entry['type'])
            );
        }
        $origin = (string) Bot::origin('https://' . (string) parse_url($directory, PHP_URL_HOST));
        $bot = $identity->getBot();
        if ($bot !== null && $bot->getSignatureAgents() !== [] && !in_array($origin, $bot->getSignatureAgents(), true)) {
            return $this->invalid(sprintf('%s does not sign with %s.', $bot->getName(), $origin), ['origin' => $origin]);
        }

        $base = $this->base->build($request, $signature);
        if ($base === null) {
            return VerificationResult::of(Result::STATE_UNVERIFIED, $method, 'The signature covers a component that cannot be rebuilt here.');
        }
        $published = $keys($directory);
        if ($published === null) {
            return VerificationResult::of(
                Result::STATE_UNVERIFIED,
                $method,
                'The signing keys of this agent are not downloaded. Run bin/magento angeo:bots:refresh.',
                ['directory' => $directory]
            );
        }
        $key = $published[$keyId] ?? null;
        if ($key === null) {
            return VerificationResult::of(
                Result::STATE_UNVERIFIED,
                $method,
                'The key is not in the stored directory. It may be new: the next refresh will pick it up.',
                ['directory' => $directory, 'keyid' => $keyId]
            );
        }
        if (strlen($signature->signature) === SODIUM_CRYPTO_SIGN_BYTES
            && sodium_crypto_sign_verify_detached($signature->signature, $base, $key)
        ) {
            return VerificationResult::of(
                Result::STATE_VERIFIED,
                $method,
                sprintf('Valid signature from %s.', $origin),
                ['origin' => $origin, 'directory' => $directory, 'keyid' => $keyId]
            );
        }
        return $this->invalid('The signature does not match the published key: treat the request as spoofed.', [
            'origin' => $origin,
            'keyid' => $keyId,
        ]);
    }

    /**
     * @param array<string, scalar|null> $details
     */
    private function invalid(string $reason, array $details = []): Result
    {
        return VerificationResult::of(Result::STATE_INVALID, Result::METHOD_WEB_BOT_AUTH, $reason, $details);
    }
}
