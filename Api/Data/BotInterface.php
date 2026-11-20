<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Api\Data;

/**
 * One known AI crawler or agent, as listed in etc/bots.json.
 *
 * @api
 * @since 1.0.0
 */
interface BotInterface
{
    public const CATEGORY_TRAINING = 'training';
    public const CATEGORY_SEARCH = 'search';
    public const CATEGORY_USER_FETCH = 'user_fetch';
    public const CATEGORY_AGENT = 'agent';
    public const CATEGORY_ADS = 'ads';
    public const CATEGORY_SEARCH_ENGINE = 'search_engine';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_DEPRECATED = 'deprecated';

    /**
     * Stable id, for example "gptbot". Never changes once released.
     */
    public function getId(): string;

    /**
     * Display name, for example "GPTBot".
     */
    public function getName(): string;

    /**
     * Company that runs the bot, for example "OpenAI".
     */
    public function getOperator(): string;

    /**
     * One of the CATEGORY_* constants.
     */
    public function getCategory(): string;

    /**
     * Short plain-English description.
     */
    public function getDescription(): string;

    /**
     * Token for a robots.txt User-agent line, or null when the bot has none.
     */
    public function getRobotsToken(): ?string;

    /**
     * True for a robots.txt token that never sends requests (Google-Extended).
     */
    public function isRobotsOnly(): bool;

    /**
     * Lower-case substrings that identify the bot in a User-Agent header.
     *
     * @return string[]
     */
    public function getUserAgentTokens(): array;

    /**
     * HTTPS URL of the operator's published IP range list, or null.
     */
    public function getIpRangesUrl(): ?string;

    /**
     * True when the list covers several bots of the operator, so a match
     * proves the operator, not this exact bot.
     */
    public function isIpRangesShared(): bool;

    /**
     * Web Bot Auth origins this bot signs with ("https://chatgpt.com").
     *
     * @return string[]
     */
    public function getSignatureAgents(): array;

    /**
     * Whether the operator says the bot follows robots.txt; null when it does
     * not say, or says "not always".
     */
    public function respectsRobots(): ?bool;

    /**
     * Whether Crawl-delay is documented as supported; null when unknown.
     */
    public function supportsCrawlDelay(): ?bool;

    /**
     * One of the STATUS_* constants.
     */
    public function getStatus(): string;

    /**
     * Operator documentation, or null.
     */
    public function getDocsUrl(): ?string;

    /**
     * Date (Y-m-d) the entry was last checked against the operator's documentation.
     */
    public function getCheckedOn(): string;

    /**
     * Whether a request can be checked at all (IP list or signing origin).
     */
    public function isVerifiable(): bool;

    /**
     * The entry as in bots.json.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
