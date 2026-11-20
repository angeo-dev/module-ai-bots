<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Model\Verify;

use Angeo\AiBots\Api\Data\VerificationResultInterface;

/**
 * Immutable verification outcome.
 */
class VerificationResult implements VerificationResultInterface
{
    /**
     * @param array<string, scalar|null> $details
     */
    public function __construct(
        private readonly string $state,
        private readonly string $method,
        private readonly string $reason,
        private readonly array $details = []
    ) {
    }

    /**
     * @param array<string, scalar|null> $details
     */
    public static function of(string $state, string $method, string $reason, array $details = []): self
    {
        return new self($state, $method, $reason, $details);
    }

    /**
     * @inheritDoc
     */
    public function getState(): string
    {
        return $this->state;
    }

    /**
     * @inheritDoc
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * @inheritDoc
     */
    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * @inheritDoc
     */
    public function isVerified(): bool
    {
        return $this->state === self::STATE_VERIFIED;
    }

    /**
     * @inheritDoc
     */
    public function isInvalid(): bool
    {
        return $this->state === self::STATE_INVALID;
    }

    /**
     * @inheritDoc
     */
    public function getDetails(): array
    {
        return $this->details;
    }
}
