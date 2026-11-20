<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Api\Data;

/**
 * AI bot traffic for one store and date range, as counted by the module
 * that records requests (angeo/module-agent-activity).
 *
 * @api
 * @since 1.0.0
 */
interface TrafficSummaryInterface
{
    /**
     * False when no recorder is installed, or it has no data for the range.
     */
    public function hasData(): bool;

    /**
     * Name of the module that provided the data, or "" when none.
     */
    public function getSource(): string;

    /**
     * First and last day (Y-m-d) the data covers.
     *
     * @return array{from: string, to: string}
     */
    public function getPeriod(): array;

    /**
     * Requests per bot id.
     *
     * @return array<string, int>
     */
    public function getVisitsByBot(): array;

    /**
     * Requests per bot category.
     *
     * @return array<string, int>
     */
    public function getVisitsByCategory(): array;

    /**
     * Requests per page type ("product", "category", "cms", "robots", …).
     *
     * @return array<string, int>
     */
    public function getVisitsByPageType(): array;

    /**
     * Requests per HTTP status class.
     *
     * @return array{'2xx': int, '3xx': int, '4xx': int, '5xx': int}
     */
    public function getStatusClasses(): array;

    /**
     * Requests per verification state (VerificationResultInterface::STATE_*).
     *
     * @return array<string, int>
     */
    public function getVerification(): array;
}
