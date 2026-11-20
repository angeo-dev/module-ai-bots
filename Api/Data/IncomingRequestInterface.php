<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Api\Data;

/**
 * The parts of an HTTP request that identification and verification need.
 * Build one with \Angeo\AiBots\Model\IncomingRequestFactory.
 *
 * @api
 * @since 1.0.0
 */
interface IncomingRequestInterface
{
    /**
     * Upper-case method, for example "GET".
     */
    public function getMethod(): string;

    /**
     * "https" or "http".
     */
    public function getScheme(): string;

    /**
     * Host and optional port, lower case, for example "shop.example".
     */
    public function getAuthority(): string;

    /**
     * Path and query as received, for example "/shoes?color=red".
     */
    public function getPathAndQuery(): string;

    /**
     * Header value by case-insensitive name, or null.
     */
    public function getHeader(string $name): ?string;

    /**
     * All headers, lower-case names.
     *
     * @return array<string, string>
     */
    public function getHeaders(): array;

    /**
     * Client address as Magento sees it. "" when unknown.
     */
    public function getRemoteIp(): string;
}
