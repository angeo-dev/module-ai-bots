<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Model;

use Angeo\AiBots\Api\BotRegistryInterface;
use Angeo\AiBots\Model\Http\SafeFetcher;
use Angeo\AiBots\Model\Signature\JwksParser;
use Angeo\AiBots\Model\Verify\RangeParser;
use Angeo\AiBots\Model\Verify\StoredLists;
use Angeo\AiBots\Model\Verify\WebBotAuthVerifier;
use Psr\Log\LoggerInterface;

/**
 * Downloads the IP lists and the Web Bot Auth key directories of the bots in
 * bots.json, and nothing else. Runs from cron once a day and from
 * bin/magento angeo:bots:refresh.
 *
 * A failed download keeps the previous copy: a vendor outage must not turn
 * every real bot into "unverified".
 */
class Refresher
{
    public const DIRECTORY_MEDIA_TYPES = ['application/http-message-signatures-directory+json', 'application/json'];

    public function __construct(
        private readonly BotRegistryInterface $registry,
        private readonly SafeFetcher $fetcher,
        private readonly RangeParser $rangeParser,
        private readonly JwksParser $jwksParser,
        private readonly StoredLists $lists,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Refresh everything.
     *
     * @return array<string, string> url => "ok: …" or "failed: …"
     */
    public function refresh(): array
    {
        $report = [];

        $ranges = $this->lists->allRanges();
        foreach ($this->rangeUrls() as $url) {
            $result = $this->fetcher->get($url, 'application/json', true);
            try {
                if (!$result->success) {
                    throw new \RuntimeException($result->error);
                }
                $prefixes = $this->rangeParser->parse($result->body);
                $ranges[$url] = ['fetched_at' => time(), 'prefixes' => $prefixes];
                $report[$url] = sprintf('ok: %d prefixes', count($prefixes));
            } catch (\Throwable $e) {
                $report[$url] = 'failed: ' . $e->getMessage();
            }
        }
        $this->lists->saveRanges(array_intersect_key($ranges, array_flip($this->rangeUrls())));

        $keys = $this->lists->allKeys();
        $wanted = [];
        foreach ($this->signatureOrigins() as $origin) {
            $url = $origin . WebBotAuthVerifier::WELL_KNOWN;
            $wanted[$url] = true;
            try {
                $found = $this->downloadKeys($url);
                $keys[$url] = ['fetched_at' => time(), 'keys' => array_map('base64_encode', $found)];
                $report[$url] = sprintf('ok: %d keys', count($found));
            } catch (\Throwable $e) {
                $report[$url] = 'failed: ' . $e->getMessage();
            }
        }
        $this->lists->saveKeys(array_intersect_key($keys, $wanted));

        $failed = array_filter($report, static fn (string $line): bool => str_starts_with($line, 'failed'));
        foreach ($failed as $url => $line) {
            $this->logger->info(sprintf('[Angeo_AiBots] %s %s', $url, $line));
        }
        $this->lists->saveStatus([
            'ran_at' => time(),
            'ok' => count($report) - count($failed),
            'failed' => count($failed),
            'errors' => array_slice($failed, 0, 10, true),
        ]);
        return $report;
    }

    /**
     * Keys of one directory, downloaded now. Used by the CLI for agents that
     * are not in the list.
     *
     * @return array<string, string> thumbprint => raw key
     * @throws \RuntimeException
     */
    public function downloadKeys(string $url): array
    {
        $result = $this->fetcher->get($url, self::DIRECTORY_MEDIA_TYPES[0] . ', application/json', false);
        if (!$result->success) {
            throw new \RuntimeException($result->error);
        }
        if (!in_array($result->mediaType(), self::DIRECTORY_MEDIA_TYPES, true)) {
            throw new \RuntimeException('unexpected media type "' . $result->mediaType() . '"');
        }
        return $this->jwksParser->parse($result->body);
    }

    /**
     * @return string[]
     */
    private function rangeUrls(): array
    {
        $urls = [];
        foreach ($this->registry->all() as $bot) {
            if ($bot->getIpRangesUrl() !== null) {
                $urls[$bot->getIpRangesUrl()] = true;
            }
        }
        return array_keys($urls);
    }

    /**
     * @return string[]
     */
    private function signatureOrigins(): array
    {
        $origins = [];
        foreach ($this->registry->all() as $bot) {
            foreach ($bot->getSignatureAgents() as $origin) {
                $origins[$origin] = true;
            }
        }
        return array_keys($origins);
    }
}
