<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Test\Unit\Model\Http;

use Angeo\AiBots\Model\Http\IpGuard;
use Angeo\AiBots\Model\Http\SafeFetcher;
use Angeo\AiBots\Model\Verify\CidrMatcher;
use Magento\Framework\HTTP\Client\Curl;
use Angeo\AiBots\Model\Http\CurlClientFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class SafeFetcherTest extends TestCase
{
    /** @var list<array{0: string, 1: array<int, mixed>}> */
    private array $calls = [];

    /**
     * @param array<string, string[]> $dns host => addresses
     * @param list<array{0: int, 1: string, 2: array<string, string>}> $responses status, body, headers
     */
    private function fetcher(array $dns, array $responses): SafeFetcher
    {
        $guard = new class (new CidrMatcher(), $dns) extends IpGuard {
            /** @param array<string, string[]> $dns */
            public function __construct(CidrMatcher $matcher, private readonly array $dns)
            {
                parent::__construct($matcher);
            }

            protected function lookup(string $host): array
            {
                return $this->dns[$host] ?? [];
            }
        };
        $factory = $this->createMock(CurlClientFactory::class);
        $factory->method('create')->willReturnCallback(function () use (&$responses) {
            [$status, $body, $headers] = array_shift($responses) ?? [500, '', []];
            $curl = $this->createMock(Curl::class);
            $options = [];
            $curl->method('setOptions')->willReturnCallback(function (array $o) use (&$options): void {
                $options = $o;
            });
            $curl->method('get')->willReturnCallback(function (string $url) use (&$options): void {
                $this->calls[] = [$url, $options];
            });
            $curl->method('getStatus')->willReturn($status);
            $curl->method('getBody')->willReturn($body);
            $curl->method('getHeaders')->willReturn($headers);
            return $curl;
        });
        return new SafeFetcher($factory, $guard, new NullLogger());
    }

    public function testFetchesAndPinsTheCheckedAddress(): void
    {
        $result = $this->fetcher(['openai.com' => ['104.18.33.45']], [[200, '{"prefixes":[]}', ['Content-Type' => 'application/json']]])
            ->get('https://openai.com/gptbot.json', 'application/json', true);
        self::assertTrue($result->success);
        self::assertSame('application/json', $result->mediaType());
        $options = $this->calls[0][1];
        self::assertSame(['openai.com:443:104.18.33.45'], $options[CURLOPT_RESOLVE]);
        self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
        self::assertSame(CURLPROTO_HTTPS, $options[CURLOPT_PROTOCOLS]);
        self::assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
    }

    /**
     * @return array<string, array{0: string, 1: array<string, string[]>}>
     */
    public static function refused(): array
    {
        return [
            'http' => ['http://openai.com/x.json', ['openai.com' => ['104.18.33.45']]],
            'credentials' => ['https://user:pass@openai.com/x.json', ['openai.com' => ['104.18.33.45']]],
            'private address' => ['https://intranet.example/x.json', ['intranet.example' => ['10.0.0.8']]],
            'metadata address' => ['https://169.254.169.254/latest/meta-data/', []],
            'one private among public' => ['https://mixed.example/x', ['mixed.example' => ['104.18.33.45', '127.0.0.1']]],
            'mapped loopback' => ['https://[::ffff:127.0.0.1]/x', []],
            'no dns' => ['https://nowhere.example/x', []],
        ];
    }

    /**
     * @dataProvider refused
     * @param array<string, string[]> $dns
     */
    public function testRefusedBeforeAnyConnection(string $url, array $dns): void
    {
        $result = $this->fetcher($dns, [[200, 'x', []]])->get($url, '*/*', true);
        self::assertFalse($result->success);
        self::assertSame([], $this->calls);
    }

    public function testSameHostRedirectIsFollowedWhenAllowed(): void
    {
        $result = $this->fetcher(
            ['perplexity.com' => ['104.18.1.1'], 'www.perplexity.com' => ['104.18.1.2']],
            [[301, '', ['Location' => 'https://www.perplexity.com/perplexitybot.json']], [200, '{}', []]]
        )->get('https://perplexity.com/perplexitybot.json', 'application/json', true);
        self::assertTrue($result->success);
        self::assertCount(2, $this->calls);
    }

    public function testCrossHostRedirectIsBlocked(): void
    {
        $result = $this->fetcher(
            ['openai.com' => ['104.18.33.45'], 'evil.example' => ['104.18.9.9']],
            [[302, '', ['Location' => 'https://evil.example/x']], [200, '{}', []]]
        )->get('https://openai.com/gptbot.json', 'application/json', true);
        self::assertFalse($result->success);
        self::assertCount(1, $this->calls);
    }

    public function testNoRedirectForKeyDirectories(): void
    {
        $result = $this->fetcher(
            ['chatgpt.com' => ['104.18.33.45']],
            [[301, '', ['Location' => 'https://chatgpt.com/other']], [200, '{}', []]]
        )->get('https://chatgpt.com/.well-known/http-message-signatures-directory', 'application/json', false);
        self::assertFalse($result->success);
        self::assertSame('redirect not followed', $result->error);
        self::assertCount(1, $this->calls);
    }

    public function testOnly200IsSuccess(): void
    {
        $result = $this->fetcher(['openai.com' => ['104.18.33.45']], [[204, '', []]])->get('https://openai.com/x', '*/*', true);
        self::assertFalse($result->success);
        self::assertSame('HTTP 204', $result->error);
    }

    public function testTooLargeBodyIsAFailureNotACut(): void
    {
        $result = $this->fetcher(['openai.com' => ['104.18.33.45']], [[200, str_repeat('x', SafeFetcher::MAX_BYTES + 1), []]])
            ->get('https://openai.com/x', '*/*', true);
        self::assertFalse($result->success);
        self::assertSame('response too large', $result->error);
    }
}
