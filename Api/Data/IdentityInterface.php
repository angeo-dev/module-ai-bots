<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Api\Data;

/**
 * Who a request says it comes from. A claim, not a proof: see
 * \Angeo\AiBots\Api\BotVerifierInterface.
 *
 * @api
 * @since 1.0.0
 */
interface IdentityInterface
{
    /** The User-Agent header names a known bot. */
    public const KIND_BOT = 'bot';

    /** No known user agent, but a Web Bot Auth Signature-Agent header. */
    public const KIND_SIGNED_AGENT = 'signed_agent';

    /** Nothing in the request names a known bot or agent. */
    public const KIND_UNKNOWN = 'unknown';

    /**
     * One of the KIND_* constants.
     */
    public function getKind(): string;

    /**
     * The matched bot, or null (unknown, or a signed agent not in the list).
     */
    public function getBot(): ?BotInterface;

    /**
     * Bot id, or the signing host for an unlisted signed agent, or "unknown".
     */
    public function getName(): string;

    /**
     * Operator, or "" when unknown.
     */
    public function getOperator(): string;

    /**
     * Bot category, "other" for an unlisted signed agent, "unknown" otherwise.
     */
    public function getCategory(): string;

    /**
     * Whether the request carries Web Bot Auth headers (Signature-Agent and Signature).
     */
    public function isSigned(): bool;

    /**
     * Signing origin from Signature-Agent ("https://chatgpt.com"), or null.
     */
    public function getSignatureAgent(): ?string;
}
