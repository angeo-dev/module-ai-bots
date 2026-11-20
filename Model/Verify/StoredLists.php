<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Model\Verify;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\FlagManager;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * What the daily refresh downloaded: IP range lists and Web Bot Auth keys.
 *
 * Stored in Magento flags (the flag table), read through the cache, so a
 * storefront request costs at most one cache read. Only public data is kept:
 * vendor IP ranges and vendor public keys.
 */
class StoredLists
{
    public const FLAG_RANGES = 'angeo_ai_bots_ip_ranges';
    public const FLAG_KEYS = 'angeo_ai_bots_signing_keys';
    public const FLAG_STATUS = 'angeo_ai_bots_refresh_status';

    private const CACHE_PREFIX = 'angeo_ai_bots_';
    private const CACHE_TAG = 'CONFIG';
    private const CACHE_LIFETIME = 86400;

    /** @var array<string, array<string, mixed>> */
    private array $memo = [];

    public function __construct(
        private readonly FlagManager $flagManager,
        private readonly CacheInterface $cache,
        private readonly SerializerInterface $serializer
    ) {
    }

    /**
     * Prefixes for one list URL, or null when it was never downloaded.
     *
     * @return string[]|null
     */
    public function prefixes(string $url): ?array
    {
        $row = $this->read(self::FLAG_RANGES)[$url] ?? null;
        return is_array($row) && is_array($row['prefixes'] ?? null) ? $row['prefixes'] : null;
    }

    /**
     * Ed25519 public keys (raw bytes) of one key directory URL, keyed by
     * JWK thumbprint; null when it was never downloaded.
     *
     * @return array<string, string>|null
     */
    public function keys(string $directoryUrl): ?array
    {
        $row = $this->read(self::FLAG_KEYS)[$directoryUrl] ?? null;
        if (!is_array($row) || !is_array($row['keys'] ?? null)) {
            return null;
        }
        $keys = [];
        foreach ($row['keys'] as $thumbprint => $base64) {
            $raw = base64_decode((string) $base64, true);
            if ($raw !== false) {
                $keys[(string) $thumbprint] = $raw;
            }
        }
        return $keys;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function allRanges(): array
    {
        return $this->read(self::FLAG_RANGES);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function allKeys(): array
    {
        return $this->read(self::FLAG_KEYS);
    }

    /**
     * @param array<string, array<string, mixed>> $lists url => ['fetched_at' => int, 'prefixes' => string[]]
     */
    public function saveRanges(array $lists): void
    {
        $this->write(self::FLAG_RANGES, $lists);
    }

    /**
     * @param array<string, array<string, mixed>> $directories url => ['fetched_at' => int, 'keys' => [thumbprint => base64]]
     */
    public function saveKeys(array $directories): void
    {
        $this->write(self::FLAG_KEYS, $directories);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function status(): ?array
    {
        $data = $this->flagManager->getFlagData(self::FLAG_STATUS);
        return is_array($data) && isset($data['ran_at']) ? $data : null;
    }

    /**
     * @param array<string, mixed> $status
     */
    public function saveStatus(array $status): void
    {
        $this->flagManager->saveFlag(self::FLAG_STATUS, $status);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function read(string $flag): array
    {
        if (isset($this->memo[$flag])) {
            return $this->memo[$flag];
        }
        $cached = $this->cache->load(self::CACHE_PREFIX . $flag);
        if (is_string($cached) && $cached !== '') {
            $data = $this->serializer->unserialize($cached);
            if (is_array($data)) {
                return $this->memo[$flag] = $data;
            }
        }
        $data = $this->flagManager->getFlagData($flag);
        $data = is_array($data) ? $data : [];
        $this->cache->save(
            (string) $this->serializer->serialize($data),
            self::CACHE_PREFIX . $flag,
            [self::CACHE_TAG],
            self::CACHE_LIFETIME
        );
        return $this->memo[$flag] = $data;
    }

    /**
     * @param array<string, array<string, mixed>> $data
     */
    private function write(string $flag, array $data): void
    {
        $this->flagManager->saveFlag($flag, $data);
        $this->cache->remove(self::CACHE_PREFIX . $flag);
        $this->memo[$flag] = $data;
    }
}
