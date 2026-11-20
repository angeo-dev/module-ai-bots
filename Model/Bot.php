<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Model;

use Angeo\AiBots\Api\Data\BotInterface;

/**
 * Immutable bot entry. Built from one row of etc/bots.json.
 */
class Bot implements BotInterface
{
    private const CATEGORIES = [
        self::CATEGORY_TRAINING,
        self::CATEGORY_SEARCH,
        self::CATEGORY_USER_FETCH,
        self::CATEGORY_AGENT,
        self::CATEGORY_ADS,
        self::CATEGORY_SEARCH_ENGINE,
    ];

    /**
     * @param string[] $userAgentTokens
     * @param string[] $signatureAgents
     */
    private function __construct(
        private readonly string $id,
        private readonly string $name,
        private readonly string $operator,
        private readonly string $category,
        private readonly string $description,
        private readonly ?string $robotsToken,
        private readonly bool $robotsOnly,
        private readonly array $userAgentTokens,
        private readonly ?string $ipRangesUrl,
        private readonly bool $ipRangesShared,
        private readonly array $signatureAgents,
        private readonly ?bool $respectsRobots,
        private readonly ?bool $crawlDelay,
        private readonly string $status,
        private readonly ?string $docsUrl,
        private readonly string $checkedOn
    ) {
    }

    /**
     * Build from a bots.json row.
     *
     * @param array<string, mixed> $row
     * @throws \InvalidArgumentException when a required field is missing or wrong
     */
    public static function fromArray(array $row): self
    {
        $id = (string) ($row['id'] ?? '');
        if (!preg_match('/^[a-z0-9_]+\z/', $id)) {
            throw new \InvalidArgumentException('bot id must be lower case letters, digits and "_"');
        }
        $category = (string) ($row['category'] ?? '');
        if (!in_array($category, self::CATEGORIES, true)) {
            throw new \InvalidArgumentException(sprintf('bot "%s": unknown category "%s"', $id, $category));
        }
        $robotsOnly = (bool) ($row['robots_only'] ?? false);
        $tokens = [];
        foreach ((array) ($row['ua_tokens'] ?? []) as $token) {
            $token = strtolower(trim((string) $token));
            if ($token !== '') {
                $tokens[] = $token;
            }
        }
        $origins = [];
        foreach ((array) ($row['signature_agents'] ?? []) as $origin) {
            $origin = self::origin((string) $origin);
            if ($origin !== null) {
                $origins[] = $origin;
            }
        }
        $status = (string) ($row['status'] ?? self::STATUS_ACTIVE);

        return new self(
            $id,
            (string) ($row['name'] ?? $id),
            (string) ($row['operator'] ?? ''),
            $category,
            (string) ($row['description'] ?? ''),
            self::optionalString($row['robots_token'] ?? null),
            $robotsOnly,
            $robotsOnly ? [] : array_values(array_unique($tokens)),
            self::httpsUrl($row['ip_ranges_url'] ?? null),
            (bool) ($row['ip_ranges_shared'] ?? false),
            array_values(array_unique($origins)),
            self::optionalBool($row['respects_robots'] ?? null),
            self::optionalBool($row['crawl_delay'] ?? null),
            $status === self::STATUS_DEPRECATED ? self::STATUS_DEPRECATED : self::STATUS_ACTIVE,
            self::httpsUrl($row['docs_url'] ?? null),
            (string) ($row['checked_on'] ?? '')
        );
    }

    /**
     * "https://Chatgpt.com/" => "https://chatgpt.com". Null for anything that
     * is not a plain https origin.
     */
    public static function origin(string $value): ?string
    {
        $parts = parse_url(trim($value));
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['query'])
            || isset($parts['fragment']) || !in_array($parts['path'] ?? '', ['', '/'], true)
        ) {
            return null;
        }
        $origin = 'https://' . strtolower((string) $parts['host']);
        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            $origin .= ':' . (int) $parts['port'];
        }
        return $origin;
    }

    /**
     * @inheritDoc
     */
    public function getId(): string
    {
        return $this->id;
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
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * @inheritDoc
     */
    public function getRobotsToken(): ?string
    {
        return $this->robotsToken;
    }

    /**
     * @inheritDoc
     */
    public function isRobotsOnly(): bool
    {
        return $this->robotsOnly;
    }

    /**
     * @inheritDoc
     */
    public function getUserAgentTokens(): array
    {
        return $this->userAgentTokens;
    }

    /**
     * @inheritDoc
     */
    public function getIpRangesUrl(): ?string
    {
        return $this->ipRangesUrl;
    }

    /**
     * @inheritDoc
     */
    public function isIpRangesShared(): bool
    {
        return $this->ipRangesShared;
    }

    /**
     * @inheritDoc
     */
    public function getSignatureAgents(): array
    {
        return $this->signatureAgents;
    }

    /**
     * @inheritDoc
     */
    public function respectsRobots(): ?bool
    {
        return $this->respectsRobots;
    }

    /**
     * @inheritDoc
     */
    public function supportsCrawlDelay(): ?bool
    {
        return $this->crawlDelay;
    }

    /**
     * @inheritDoc
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * @inheritDoc
     */
    public function getDocsUrl(): ?string
    {
        return $this->docsUrl;
    }

    /**
     * @inheritDoc
     */
    public function getCheckedOn(): string
    {
        return $this->checkedOn;
    }

    /**
     * @inheritDoc
     */
    public function isVerifiable(): bool
    {
        return $this->ipRangesUrl !== null || $this->signatureAgents !== [];
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'operator' => $this->operator,
            'category' => $this->category,
            'description' => $this->description,
            'robots_token' => $this->robotsToken,
            'robots_only' => $this->robotsOnly,
            'ua_tokens' => $this->userAgentTokens,
            'ip_ranges_url' => $this->ipRangesUrl,
            'ip_ranges_shared' => $this->ipRangesShared,
            'signature_agents' => $this->signatureAgents,
            'respects_robots' => $this->respectsRobots,
            'crawl_delay' => $this->crawlDelay,
            'status' => $this->status,
            'docs_url' => $this->docsUrl,
            'checked_on' => $this->checkedOn,
        ];
    }

    /**
     * Non-empty string or null.
     */
    private static function optionalString(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';
        return $value === '' ? null : $value;
    }

    /**
     * Bool or null.
     */
    private static function optionalBool(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    /**
     * An https URL or null.
     */
    private static function httpsUrl(mixed $value): ?string
    {
        $value = self::optionalString($value);
        return $value !== null && str_starts_with(strtolower($value), 'https://')
            && filter_var($value, FILTER_VALIDATE_URL) !== false ? $value : null;
    }
}
