<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Model\Http;

use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;

/**
 * The only way this module talks to the network. Used by the daily refresh
 * and the CLI, never on a storefront request.
 *
 * Rules (the Web Bot Auth draft asks for the same protections):
 *  - HTTPS only, TLS certificates checked;
 *  - the host must resolve only to public addresses; the checked address is
 *    pinned for the connection (CURLOPT_RESOLVE), so DNS rebinding cannot
 *    swap it for a private one;
 *  - no automatic redirects. When the caller allows redirects, at most 2 are
 *    followed by hand, each to the same host (a leading "www." may differ)
 *    and each checked again. Key directories allow none;
 *  - only HTTP 200 is a success;
 *  - 10 second timeout, body capped at MAX_BYTES (larger is a failure,
 *    never a cut-off document).
 */
class SafeFetcher
{
    public const USER_AGENT = 'Angeo-AiBots/1.0 (+https://angeo.dev)';
    public const TIMEOUT = 10;
    public const MAX_BYTES = 2097152;
    public const MAX_REDIRECTS = 2;

    private const REDIRECTS = [301, 302, 303, 307, 308];

    public function __construct(
        private readonly CurlClientFactory $curlFactory,
        private readonly IpGuard $ipGuard,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * GET a URL under the rules above.
     */
    public function get(string $url, string $accept, bool $allowRedirects): FetchResult
    {
        $error = $this->validate($url);
        if ($error !== null) {
            return FetchResult::failure($url, $error);
        }

        $current = $url;
        $hops = $allowRedirects ? self::MAX_REDIRECTS : 0;
        for ($hop = 0; $hop <= $hops; $hop++) {
            $host = (string) parse_url($current, PHP_URL_HOST);
            $addresses = $this->ipGuard->resolveAllowedAddresses($host);
            if ($addresses === []) {
                return FetchResult::failure($current, 'host does not resolve to a public address');
            }

            try {
                $curl = $this->curlFactory->create();
                $this->configure($curl, $current, $addresses, $accept);
                $curl->get($current);
            } catch (\Throwable $e) {
                $this->logger->info('[Angeo_AiBots] Fetch failed for ' . $current . ': ' . $e->getMessage());
                return FetchResult::failure($current, 'network error');
            }

            $status = (int) $curl->getStatus();
            $headers = $this->headers($curl->getHeaders());
            if ($status === 200) {
                $body = (string) $curl->getBody();
                if (strlen($body) > self::MAX_BYTES) {
                    return FetchResult::failure($current, 'response too large', $status);
                }
                return FetchResult::success($current, $status, $body, $headers);
            }
            if (!in_array($status, self::REDIRECTS, true) || $hop === $hops) {
                return FetchResult::failure(
                    $current,
                    in_array($status, self::REDIRECTS, true) ? 'redirect not followed' : 'HTTP ' . $status,
                    $status
                );
            }
            $next = $this->redirectTarget($url, $current, $headers['location'] ?? '');
            if ($next === null) {
                return FetchResult::failure($current, 'redirect to another host was blocked', $status);
            }
            $current = $next;
        }

        return FetchResult::failure($url, 'too many redirects');
    }

    /**
     * Error text, or null when the URL may be fetched.
     */
    private function validate(string $url): ?string
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return 'invalid URL';
        }
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return 'not an HTTPS URL';
        }
        if (empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return 'URL has no host, or has credentials';
        }
        return null;
    }

    /**
     * Next URL, or null when the redirect leaves the host.
     */
    private function redirectTarget(string $original, string $current, string $location): ?string
    {
        $location = trim($location);
        if ($location === '') {
            return null;
        }
        if (str_starts_with($location, '/') && !str_starts_with($location, '//')) {
            $location = 'https://' . parse_url($current, PHP_URL_HOST) . $location;
        }
        if ($this->validate($location) !== null) {
            return null;
        }
        $strip = static fn (string $host): string => str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        $from = $strip(strtolower((string) parse_url($original, PHP_URL_HOST)));
        $to = $strip(strtolower((string) parse_url($location, PHP_URL_HOST)));
        return $from !== '' && $from === $to ? $location : null;
    }

    /**
     * @param string[] $addresses
     */
    private function configure(Curl $curl, string $url, array $addresses, string $accept): void
    {
        $options = [
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXFILESIZE => self::MAX_BYTES,
        ];
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) === false) {
            $port = (int) (parse_url($url, PHP_URL_PORT) ?: 443);
            $options[CURLOPT_RESOLVE] = array_map(
                static fn (string $address): string => sprintf(
                    '%s:%d:%s',
                    $host,
                    $port,
                    str_contains($address, ':') ? '[' . $address . ']' : $address
                ),
                $addresses
            );
            $options[CURLOPT_DNS_CACHE_TIMEOUT] = 0;
        }
        $curl->setOptions($options);
        $curl->addHeader('Accept', $accept);
    }

    /**
     * @param mixed $headers
     * @return array<string, string>
     */
    private function headers(mixed $headers): array
    {
        $result = [];
        if (!is_array($headers)) {
            return $result;
        }
        foreach ($headers as $name => $value) {
            $result[strtolower((string) $name)] = is_array($value) ? (string) reset($value) : (string) $value;
        }
        return $result;
    }
}
