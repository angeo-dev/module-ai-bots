<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Model;

use Angeo\AiBots\Api\Data\BotInterface;
use Angeo\AiBots\Api\Data\IdentityInterface;

/**
 * Immutable identification result.
 */
class Identity implements IdentityInterface
{
    public const CATEGORY_OTHER = 'other';
    public const CATEGORY_UNKNOWN = 'unknown';

    public function __construct(
        private readonly string $kind,
        private readonly ?BotInterface $bot,
        private readonly string $name,
        private readonly string $operator,
        private readonly string $category,
        private readonly bool $signed,
        private readonly ?string $signatureAgent
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getKind(): string
    {
        return $this->kind;
    }

    /**
     * @inheritDoc
     */
    public function getBot(): ?BotInterface
    {
        return $this->bot;
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @inheritDoc
     */
    public function getOperator(): string
    {
        return $this->operator;
    }

    /**
     * @inheritDoc
     */
    public function getCategory(): string
    {
        return $this->category;
    }

    /**
     * @inheritDoc
     */
    public function isSigned(): bool
    {
        return $this->signed;
    }

    /**
     * @inheritDoc
     */
    public function getSignatureAgent(): ?string
    {
        return $this->signatureAgent;
    }
}
