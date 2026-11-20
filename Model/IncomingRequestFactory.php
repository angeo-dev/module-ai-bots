<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Model;

use Angeo\AiBots\Api\Data\IncomingRequestInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;

/**
 * Builds the request snapshot that identification and verification use.
 *
 * The client address comes from Magento's RemoteAddress, so it follows the
 * store's trusted proxy setup (alternative headers in di.xml or env.php).
 * This module never reads X-Forwarded-For on its own: that header is set by
 * the client and proves nothing.
 *
 * @api
 */
class IncomingRequestFactory
{
    /** Only these headers are kept: the rest is not needed and may be personal. */
    private const HEADERS = ['user-agent', 'signature-agent', 'signature-input', 'signature', 'host', 'content-digest'];

    public function __construct(
        private readonly RemoteAddress $remoteAddress
    ) {
    }

    /**
     * Snapshot of a Magento request.
     */
    public function fromHttp(HttpRequest $request): IncomingRequestInterface
    {
        $headers = [];
        $names = self::HEADERS;
        // A signature may cover other headers too; keep exactly those.
        $input = $request->getHeader('signature-input');
        if (is_string($input) && preg_match_all('/"([a-z0-9-]+)"/', strtolower($input), $m)) {
            $names = array_unique(array_merge($names, array_slice($m[1], 0, 20)));
        }
        foreach ($names as $name) {
            $value = $request->getHeader($name);
            if (is_string($value) && $value !== '') {
                $headers[$name] = $value;
            }
        }
        $uri = (string) $request->getRequestUri();

        return new IncomingRequest(
            (string) $request->getMethod(),
            $request->isSecure() ? 'https' : 'http',
            (string) $request->getHttpHost(),
            $uri === '' ? '/' : $uri,
            $headers,
            (string) $this->remoteAddress->getRemoteAddress()
        );
    }

    /**
     * Snapshot from plain values (CLI, tests, other modules).
     *
     * @param array<string, string|string[]> $headers
     */
    public function create(
        array $headers,
        string $remoteIp = '',
        string $method = 'GET',
        string $scheme = 'https',
        string $authority = '',
        string $pathAndQuery = '/'
    ): IncomingRequestInterface {
        return new IncomingRequest($method, $scheme, $authority, $pathAndQuery, $headers, $remoteIp);
    }
}
