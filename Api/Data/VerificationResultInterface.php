<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Api\Data;

/**
 * Outcome of checking that a request really comes from the bot it names.
 *
 * The three main states follow the Web Bot Auth draft
 * (draft-ietf-webbotauth-httpsig-protocol): "unverified" is not "invalid".
 *
 * @api
 * @since 1.0.0
 */
interface VerificationResultInterface
{
    /** IP address in the operator's list, or a valid signature. */
    public const STATE_VERIFIED = 'verified';

    /** The proof was checked and failed: treat the claim as spoofed. */
    public const STATE_INVALID = 'invalid';

    /** Could not be checked now (list not downloaded, unknown key, unsupported algorithm). */
    public const STATE_UNVERIFIED = 'unverified';

    /** Magento sees a private address: a proxy without trusted client-IP headers. */
    public const STATE_BEHIND_PROXY = 'behind_proxy';

    /** The operator publishes no way to check this bot. */
    public const STATE_UNSUPPORTED = 'unsupported';

    public const METHOD_IP_RANGE = 'ip_range';
    public const METHOD_WEB_BOT_AUTH = 'web_bot_auth';
    public const METHOD_NONE = 'none';

    /**
     * One of the STATE_* constants.
     */
    public function getState(): string;

    /**
     * One of the METHOD_* constants.
     */
    public function getMethod(): string;

    /**
     * Plain-English reason, safe to show to an admin.
     */
    public function getReason(): string;

    /**
     * Shortcut for getState() === STATE_VERIFIED.
     */
    public function isVerified(): bool;

    /**
     * Shortcut for getState() === STATE_INVALID.
     */
    public function isInvalid(): bool;

    /**
     * Extra facts (key id, directory URL, list URL). No secrets.
     *
     * @return array<string, scalar|null>
     */
    public function getDetails(): array;
}
