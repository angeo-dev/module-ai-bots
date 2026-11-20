<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Test\Unit\Data;

use PHPUnit\Framework\TestCase;

/**
 * Guards etc/bots.json: the file every Angeo module and the scanner read.
 */
class BotsJsonTest extends TestCase
{
    /** An entry not checked against the operator's docs for this long fails CI. */
    private const MAX_AGE_DAYS = 183;

    private const CATEGORIES = ['training', 'search', 'user_fetch', 'agent', 'ads', 'search_engine'];

    /**
     * @return array<string, mixed>
     */
    private static function data(): array
    {
        $data = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/etc/bots.json'), true);
        self::assertIsArray($data);
        return $data;
    }

    public function testTopLevel(): void
    {
        $data = self::data();
        self::assertSame(1, $data['schema_version']);
        self::assertSame(self::CATEGORIES, array_keys($data['categories']));
        foreach (['bots', 'signature_agents', 'mcp_clients', 'test_tools', 'ai_referrers', 'ai_utm_sources'] as $key) {
            self::assertArrayHasKey($key, $data);
        }
    }

    public function testEveryBotIsComplete(): void
    {
        $fields = ['id', 'name', 'operator', 'category', 'description', 'robots_token', 'robots_only', 'ua_tokens',
            'ip_ranges_url', 'ip_ranges_shared', 'signature_agents', 'respects_robots', 'crawl_delay', 'status',
            'docs_url', 'checked_on'];
        $ids = [];
        foreach (self::data()['bots'] as $bot) {
            self::assertSame($fields, array_keys($bot), 'fields of ' . ($bot['id'] ?? '?'));
            self::assertMatchesRegularExpression('/^[a-z0-9_]+$/', $bot['id']);
            self::assertArrayNotHasKey($bot['id'], $ids, 'duplicate id ' . $bot['id']);
            $ids[$bot['id']] = true;
            self::assertContains($bot['category'], self::CATEGORIES, $bot['id']);
            self::assertContains($bot['status'], ['active', 'deprecated'], $bot['id']);
            self::assertNotSame('', $bot['description'], $bot['id']);
            foreach (['ip_ranges_url', 'docs_url'] as $url) {
                if ($bot[$url] !== null) {
                    self::assertStringStartsWith('https://', $bot[$url], $bot['id'] . ' ' . $url);
                }
            }
            foreach ($bot['signature_agents'] as $origin) {
                self::assertMatchesRegularExpression('#^https://[a-z0-9.-]+$#', $origin, $bot['id']);
            }
            if ($bot['robots_only']) {
                self::assertSame([], $bot['ua_tokens'], $bot['id'] . ': a robots.txt token sends no requests');
                self::assertNotNull($bot['robots_token'], $bot['id']);
            }
            if (!$bot['robots_only'] && $bot['ua_tokens'] === []) {
                self::assertNotSame([], $bot['signature_agents'], $bot['id'] . ' cannot be identified at all');
            }
            if ($bot['ip_ranges_shared']) {
                self::assertNotNull($bot['ip_ranges_url'], $bot['id']);
            }
        }
    }

    public function testUserAgentTokensBelongToOneBot(): void
    {
        $owner = [];
        foreach (self::data()['bots'] as $bot) {
            foreach ($bot['ua_tokens'] as $token) {
                self::assertSame(strtolower($token), $token);
                self::assertArrayNotHasKey($token, $owner, sprintf('token "%s" is used by %s and %s', $token, $owner[$token] ?? '', $bot['id']));
                $owner[$token] = $bot['id'];
            }
        }
    }

    public function testRobotsTokensAreUnique(): void
    {
        $seen = [];
        foreach (self::data()['bots'] as $bot) {
            if ($bot['robots_token'] === null) {
                continue;
            }
            $key = strtolower($bot['robots_token']);
            self::assertArrayNotHasKey($key, $seen, 'robots token ' . $bot['robots_token']);
            $seen[$key] = true;
        }
    }

    public function testSignatureAgentsPointAtListedBots(): void
    {
        $data = self::data();
        $ids = array_column($data['bots'], null, 'id');
        foreach ($data['signature_agents'] as $host => $id) {
            self::assertArrayHasKey($id, $ids, $host);
            self::assertContains('https://' . $host, $ids[$id]['signature_agents'], $host . ' must be a trusted origin of ' . $id);
        }
    }

    public function testEntriesAreRecentlyChecked(): void
    {
        $limit = (new \DateTimeImmutable('today'))->modify('-' . self::MAX_AGE_DAYS . ' days');
        foreach (self::data()['bots'] as $bot) {
            $checked = \DateTimeImmutable::createFromFormat('!Y-m-d', $bot['checked_on']);
            self::assertNotFalse($checked, $bot['id']);
            self::assertGreaterThanOrEqual(
                $limit,
                $checked,
                sprintf('%s was last checked on %s. Check it against %s and update checked_on.', $bot['id'], $bot['checked_on'], $bot['docs_url'] ?? 'the operator docs')
            );
        }
    }

    public function testSchemaFileIsValidJson(): void
    {
        $schema = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/etc/bots.schema.json'), true);
        self::assertIsArray($schema);
        self::assertSame(
            ['id', 'name', 'operator', 'category', 'description', 'robots_token', 'robots_only', 'ua_tokens',
                'ip_ranges_url', 'ip_ranges_shared', 'signature_agents', 'respects_robots', 'crawl_delay', 'status',
                'docs_url', 'checked_on'],
            $schema['$defs']['bot']['required']
        );
    }
}
