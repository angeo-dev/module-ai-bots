<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Test\Unit\Model;

use Angeo\AiBots\Model\Http\FetchResult;
use Angeo\AiBots\Model\Http\SafeFetcher;
use Angeo\AiBots\Model\Refresher;
use Angeo\AiBots\Model\Signature\JwksParser;
use Angeo\AiBots\Model\Verify\RangeParser;
use Angeo\AiBots\Model\Verify\StoredLists;
use Angeo\AiBots\Test\Unit\Model\Signature\Rfc9421VectorTest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class RefresherTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $saved = [];

    /** @var list<array{0: string, 1: bool}> */
    private array $fetched = [];

    /**
     * @param callable(string): FetchResult $respond
     * @param array<string, array<string, mixed>> $oldRanges
     */
    private function refresher(callable $respond, array $oldRanges = []): Refresher
    {
        $fetcher = $this->createMock(SafeFetcher::class);
        $fetcher->method('get')->willReturnCallback(function (string $url, string $accept, bool $redirects) use ($respond) {
            $this->fetched[] = [$url, $redirects];
            return $respond($url);
        });
        $lists = $this->createMock(StoredLists::class);
        $lists->method('allRanges')->willReturn($oldRanges);
        $lists->method('allKeys')->willReturn([]);
        $lists->method('saveRanges')->willReturnCallback(function (array $d): void {
            $this->saved['ranges'] = $d;
        });
        $lists->method('saveKeys')->willReturnCallback(function (array $d): void {
            $this->saved['keys'] = $d;
        });
        $lists->method('saveStatus')->willReturnCallback(function (array $d): void {
            $this->saved['status'] = $d;
        });
        return new Refresher(BotRegistryTest::registry(), $fetcher, new RangeParser(), new JwksParser(), $lists, new NullLogger());
    }

    private static function jwks(): FetchResult
    {
        return FetchResult::success('x', 200, (string) json_encode(['keys' => [
            ['kty' => 'OKP', 'crv' => 'Ed25519', 'x' => Rfc9421VectorTest::TEST_KEY_X],
        ]]), ['content-type' => 'application/http-message-signatures-directory+json']);
    }

    public function testDownloadsOnlyListedUrlsWithTheRightRedirectPolicy(): void
    {
        $report = $this->refresher(static function (string $url): FetchResult {
            return str_contains($url, 'http-message-signatures-directory')
                ? self::jwks()
                : FetchResult::success($url, 200, '{"prefixes":[{"ipv4Prefix":"20.171.206.0/24"}]}', ['content-type' => 'application/json']);
        })->refresh();

        foreach ($this->fetched as [$url, $redirects]) {
            self::assertStringStartsWith('https://', $url);
            self::assertSame(!str_contains($url, '/.well-known/'), $redirects, $url);
        }
        self::assertArrayHasKey('https://openai.com/gptbot.json', $report);
        self::assertArrayHasKey('https://chatgpt.com/.well-known/http-message-signatures-directory', $report);
        self::assertArrayHasKey('https://agent.bot.goog/.well-known/http-message-signatures-directory', $report);
        self::assertSame(0, $this->saved['status']['failed']);
        self::assertSame(
            [JwksParser::thumbprint(Rfc9421VectorTest::TEST_KEY_X)],
            array_keys($this->saved['keys']['https://chatgpt.com/.well-known/http-message-signatures-directory']['keys'])
        );
    }

    public function testFailedDownloadKeepsTheOldCopyAndDropsUnlistedUrls(): void
    {
        $old = [
            'https://openai.com/gptbot.json' => ['fetched_at' => 1, 'prefixes' => ['1.2.3.0/24']],
            'https://removed.example/list.json' => ['fetched_at' => 1, 'prefixes' => ['5.6.7.0/24']],
        ];
        $this->refresher(static fn (string $url): FetchResult => FetchResult::failure($url, 'HTTP 503', 503), $old)->refresh();

        self::assertSame(['1.2.3.0/24'], $this->saved['ranges']['https://openai.com/gptbot.json']['prefixes']);
        self::assertArrayNotHasKey('https://removed.example/list.json', $this->saved['ranges']);
        self::assertGreaterThan(0, $this->saved['status']['failed']);
    }

    public function testDirectoryWithWrongMediaTypeIsRefused(): void
    {
        $refresher = $this->refresher(static fn (string $url): FetchResult => FetchResult::success($url, 200, '{"keys":[]}', ['content-type' => 'text/html']));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unexpected media type');
        $refresher->downloadKeys('https://chatgpt.com/.well-known/http-message-signatures-directory');
    }
}
