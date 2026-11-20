<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Model;

use Angeo\AiBots\Api\Data\IncomingRequestInterface;

/**
 * Immutable request snapshot. Header names are stored lower case; a header
 * sent several times is joined with ", " as RFC 9110 allows.
 */
class IncomingRequest implements IncomingRequestInterface
{
    /** Longest header value kept. Longer values are dropped, not cut. */
    public const MAX_HEADER_LENGTH = 8192;

    /** @var array<string, string> */
    private array $headers = [];

    /**
     * @param array<string, string|string[]> $headers
     */
    public function __construct(
        private readonly string $method,
        private readonly string $scheme,
        private readonly string $authority,
        private readonly string $pathAndQuery,
        array $headers,
        private readonly string $remoteIp
    ) {
        foreach ($headers as $name => $value) {
            $value = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
            $value = trim($value);
            if (strlen($value) <= self::MAX_HEADER_LENGTH) {
                $this->headers[strtolower(trim((string) $name))] = $value;
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function getMethod(): string
    {
        return strtoupper($this->method);
    }

    /**
     * @inheritDoc
     */
    public function getScheme(): string
    {
        return strtolower($this->scheme);
    }

    /**
     * @inheritDoc
     */
    public function getAuthority(): string
    {
        return strtolower($this->authority);
    }

    /**
     * @inheritDoc
     */
    public function getPathAndQuery(): string
    {
        return $this->pathAndQuery === '' ? '/' : $this->pathAndQuery;
    }

    /**
     * @inheritDoc
     */
    public function getHeader(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * @inheritDoc
     */
    public function getRemoteIp(): string
    {
        return $this->remoteIp;
    }
}
