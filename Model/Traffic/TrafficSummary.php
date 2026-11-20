<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Model\Traffic;

use Angeo\AiBots\Api\Data\TrafficSummaryInterface;

/**
 * Immutable traffic summary. Recorders build one with the constructor.
 *
 * @api
 */
class TrafficSummary implements TrafficSummaryInterface
{
    /**
     * @param array<string, int> $visitsByBot
     * @param array<string, int> $visitsByCategory
     * @param array<string, int> $visitsByPageType
     * @param array<string, int> $statusClasses
     * @param array<string, int> $verification
     */
    public function __construct(
        private readonly bool $hasData,
        private readonly string $source,
        private readonly string $from,
        private readonly string $to,
        private readonly array $visitsByBot = [],
        private readonly array $visitsByCategory = [],
        private readonly array $visitsByPageType = [],
        private readonly array $statusClasses = [],
        private readonly array $verification = []
    ) {
    }

    /**
     * An empty summary: no recorder, or no data.
     */
    public static function empty(string $from, string $to): self
    {
        return new self(false, '', $from, $to);
    }

    /**
     * @inheritDoc
     */
    public function hasData(): bool
    {
        return $this->hasData;
    }

    /**
     * @inheritDoc
     */
    public function getSource(): string
    {
        return $this->source;
    }

    /**
     * @inheritDoc
     */
    public function getPeriod(): array
    {
        return ['from' => $this->from, 'to' => $this->to];
    }

    /**
     * @inheritDoc
     */
    public function getVisitsByBot(): array
    {
        return $this->visitsByBot;
    }

    /**
     * @inheritDoc
     */
    public function getVisitsByCategory(): array
    {
        return $this->visitsByCategory;
    }

    /**
     * @inheritDoc
     */
    public function getVisitsByPageType(): array
    {
        return $this->visitsByPageType;
    }

    /**
     * @inheritDoc
     */
    public function getStatusClasses(): array
    {
        return $this->statusClasses + ['2xx' => 0, '3xx' => 0, '4xx' => 0, '5xx' => 0];
    }

    /**
     * @inheritDoc
     */
    public function getVerification(): array
    {
        return $this->verification;
    }
}
