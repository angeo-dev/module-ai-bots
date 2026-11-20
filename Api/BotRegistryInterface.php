<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Api;

use Angeo\AiBots\Api\Data\BotInterface;

/**
 * The one list of known AI crawlers, agents, MCP clients and AI referrers.
 *
 * The list ships in etc/bots.json and changes only with a release. The module
 * never downloads it.
 *
 * @api
 * @since 1.0.0
 */
interface BotRegistryInterface
{
    /**
     * Every bot, keyed by id, in file order.
     *
     * @return array<string, BotInterface>
     */
    public function all(): array;

    /**
     * One bot by id, or null.
     */
    public function get(string $id): ?BotInterface;

    /**
     * The bot a User-Agent header names, or null. The longest matching token
     * wins, so "Amzn-SearchBot" is not taken for another Amazon bot.
     */
    public function match(string $userAgent): ?BotInterface;

    /**
     * The bot that signs with this Web Bot Auth host ("chatgpt.com"), or null.
     */
    public function matchSignatureAgent(string $host): ?BotInterface;

    /**
     * Name and operator for an MCP client that named itself in clientInfo.
     *
     * @return array{name: string, operator: string}|null null for an empty name
     */
    public function matchMcpClient(string $clientName): ?array;

    /**
     * Whether an agent name belongs to a test or audit tool.
     */
    public function isTestTool(string $name): bool;

    /**
     * AI source for a referrer host ("chatgpt.com"), or null.
     *
     * @return array{source: string, operator: string}|null
     */
    public function matchReferrer(string $host): ?array;

    /**
     * AI source for a utm_source value, or null.
     */
    public function matchUtmSource(string $utmSource): ?string;

    /**
     * Category id => label.
     *
     * @return array<string, string>
     */
    public function categories(): array;
}
