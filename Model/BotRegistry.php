<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Model;

use Angeo\AiBots\Api\BotRegistryInterface;
use Angeo\AiBots\Api\Data\BotInterface;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Module\Dir;
use Psr\Log\LoggerInterface;

/**
 * Reads etc/bots.json once per process.
 *
 * The list is part of the release: it is never downloaded, so whoever runs a
 * web server cannot change what every store believes about bots. Integrators
 * can add or override rows through di.xml (still a deployed, reviewed file):
 *
 *   <type name="Angeo\AiBots\Model\BotRegistry">
 *       <arguments>
 *           <argument name="additionalBots" xsi:type="array">
 *               <item name="my_bot" xsi:type="array">
 *                   <item name="id" xsi:type="string">my_bot</item>
 *                   <item name="name" xsi:type="string">MyBot</item>
 *                   <item name="operator" xsi:type="string">Example</item>
 *                   <item name="category" xsi:type="string">search</item>
 *                   <item name="ua_tokens" xsi:type="array">
 *                       <item name="0" xsi:type="string">mybot</item>
 *                   </item>
 *               </item>
 *           </argument>
 *       </arguments>
 *   </type>
 *
 * A broken file never breaks the store: the error is logged and the list is
 * empty, so every request is simply "unknown".
 */
class BotRegistry implements BotRegistryInterface
{
    public const FILE = 'bots.json';

    /** @var array<string, mixed>|null */
    private ?array $data = null;

    /** @var array<string, BotInterface>|null */
    private ?array $bots = null;

    /** @var array<string, string>|null token => bot id, longest token first */
    private ?array $tokens = null;

    /**
     * @param array<string, array<string, mixed>> $additionalBots rows added through di.xml
     * @param string|null $file absolute path, for tests; null = etc/bots.json of this module
     */
    public function __construct(
        private readonly Dir $moduleDir,
        private readonly File $driver,
        private readonly LoggerInterface $logger,
        private readonly array $additionalBots = [],
        private readonly ?string $file = null
    ) {
    }

    /**
     * @inheritDoc
     */
    public function all(): array
    {
        if ($this->bots !== null) {
            return $this->bots;
        }
        $rows = [];
        foreach ((array) ($this->data()['bots'] ?? []) as $row) {
            if (is_array($row) && isset($row['id'])) {
                $rows[(string) $row['id']] = $row;
            }
        }
        foreach ($this->additionalBots as $key => $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string) ($row['id'] ?? $key);
            $rows[$id] = array_merge($rows[$id] ?? [], $row, ['id' => $id]);
        }

        $bots = [];
        foreach ($rows as $id => $row) {
            try {
                $bots[$id] = Bot::fromArray($row);
            } catch (\InvalidArgumentException $e) {
                $this->logger->warning('[Angeo_AiBots] Skipped bot entry: ' . $e->getMessage());
            }
        }
        return $this->bots = $bots;
    }

    /**
     * @inheritDoc
     */
    public function get(string $id): ?BotInterface
    {
        return $this->all()[$id] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function match(string $userAgent): ?BotInterface
    {
        $ua = strtolower($userAgent);
        if ($ua === '') {
            return null;
        }
        foreach ($this->tokens() as $token => $id) {
            if (str_contains($ua, (string) $token)) {
                return $this->all()[$id] ?? null;
            }
        }
        return null;
    }

    /**
     * @inheritDoc
     */
    public function matchSignatureAgent(string $host): ?BotInterface
    {
        $host = strtolower(trim($host));
        $map = (array) ($this->data()['signature_agents'] ?? []);
        $id = $map[$host] ?? null;
        return is_string($id) ? $this->get($id) : null;
    }

    /**
     * @inheritDoc
     */
    public function matchMcpClient(string $clientName): ?array
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($clientName)), '-');
        if ($slug === '') {
            return null;
        }
        $slug = substr($slug, 0, 64);
        foreach ((array) ($this->data()['mcp_clients'] ?? []) as $pair) {
            if (is_array($pair) && isset($pair[0], $pair[1]) && str_starts_with($slug, (string) $pair[0])) {
                return ['name' => $slug, 'operator' => (string) $pair[1]];
            }
        }
        return ['name' => $slug, 'operator' => ''];
    }

    /**
     * @inheritDoc
     */
    public function isTestTool(string $name): bool
    {
        foreach ((array) ($this->data()['test_tools'] ?? []) as $prefix) {
            if (is_string($prefix) && $prefix !== '' && str_starts_with($name, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @inheritDoc
     */
    public function matchReferrer(string $host): ?array
    {
        $host = strtolower(trim($host));
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        foreach ((array) ($this->data()['ai_referrers'] ?? []) as $row) {
            if (!is_array($row) || !isset($row['host'], $row['source'])) {
                continue;
            }
            $known = (string) $row['host'];
            if ($host === $known || str_ends_with($host, '.' . $known)) {
                return ['source' => (string) $row['source'], 'operator' => (string) ($row['operator'] ?? '')];
            }
        }
        return null;
    }

    /**
     * @inheritDoc
     */
    public function matchUtmSource(string $utmSource): ?string
    {
        $map = (array) ($this->data()['ai_utm_sources'] ?? []);
        $value = $map[strtolower(trim($utmSource))] ?? null;
        return is_string($value) ? $value : null;
    }

    /**
     * @inheritDoc
     */
    public function categories(): array
    {
        $categories = [];
        foreach ((array) ($this->data()['categories'] ?? []) as $id => $label) {
            $categories[(string) $id] = (string) $label;
        }
        return $categories;
    }

    /**
     * The decoded file. Empty array when it cannot be read.
     *
     * @return array<string, mixed>
     */
    private function data(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }
        $path = $this->file ?? $this->moduleDir->getDir('Angeo_AiBots', Dir::MODULE_ETC_DIR) . '/' . self::FILE;
        try {
            $decoded = json_decode((string) $this->driver->fileGetContents($path), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || ($decoded['schema_version'] ?? null) !== 1) {
                throw new \UnexpectedValueException('unknown schema_version');
            }
            return $this->data = $decoded;
        } catch (\Throwable $e) {
            $this->logger->error('[Angeo_AiBots] Cannot read the bot list: ' . $e->getMessage());
            return $this->data = [];
        }
    }

    /**
     * @return array<string, string>
     */
    private function tokens(): array
    {
        if ($this->tokens !== null) {
            return $this->tokens;
        }
        $tokens = [];
        foreach ($this->all() as $bot) {
            foreach ($bot->getUserAgentTokens() as $token) {
                $tokens[$token] ??= $bot->getId();
            }
        }
        uksort($tokens, static fn (string $a, string $b): int => strlen($b) <=> strlen($a) ?: strcmp($a, $b));
        return $this->tokens = $tokens;
    }
}
