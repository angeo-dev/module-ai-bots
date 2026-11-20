<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Model\Http;

/**
 * Result of one SafeFetcher call. Never thrown: callers always get one.
 */
class FetchResult
{
    /**
     * @param array<string, string> $headers lower-case name => value
     */
    private function __construct(
        public readonly string $url,
        public readonly bool $success,
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers,
        public readonly string $error
    ) {
    }

    /**
     * @param array<string, string> $headers
     */
    public static function success(string $url, int $status, string $body, array $headers): self
    {
        return new self($url, true, $status, $body, $headers, '');
    }

    public static function failure(string $url, string $error, int $status = 0): self
    {
        return new self($url, false, $status, '', [], $error);
    }

    /**
     * Media type without parameters, lower case ("application/json").
     */
    public function mediaType(): string
    {
        $type = $this->headers['content-type'] ?? '';
        return strtolower(trim(explode(';', $type)[0]));
    }
}
