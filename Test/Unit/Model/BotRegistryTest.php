<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Test\Unit\Model;

use Angeo\AiBots\Model\BotRegistry;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Module\Dir;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BotRegistryTest extends TestCase
{
    /**
     * @param array<string, array<string, mixed>> $additional
     */
    public static function registry(array $additional = [], ?string $file = null, ?LoggerInterface $logger = null): BotRegistry
    {
        $driver = new class extends File {
            public function __construct()
            {
            }

            public function fileGetContents($path, $flag = null, $context = null)
            {
                if (!is_file($path)) {
                    throw new \RuntimeException('cannot read ' . $path);
                }
                return (string) file_get_contents($path);
            }
        };
        $dir = (new \ReflectionClass(Dir::class))->newInstanceWithoutConstructor();
        return new BotRegistry(
            $dir,
            $driver,
            $logger ?? new \Psr\Log\NullLogger(),
            $additional,
            $file ?? dirname(__DIR__, 3) . '/etc/bots.json'
        );
    }

    public function testLoadsEveryBot(): void
    {
        $all = self::registry()->all();
        self::assertCount(38, $all);
        self::assertSame('GPTBot', $all['gptbot']->getName());
        self::assertSame('OpenAI', $all['gptbot']->getOperator());
    }

    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function userAgents(): array
    {
        return [
            'gptbot' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.3; +https://openai.com/gptbot', 'gptbot'],
            'chatgpt user' => ['Mozilla/5.0 (compatible; ChatGPT-User/1.0; +https://openai.com/bot)', 'chatgpt_user'],
            'claude user' => ['Claude-User/1.0 (+Claude-User@anthropic.com)', 'claude_user'],
            'amazon search' => ['Mozilla/5.0 (compatible; Amzn-SearchBot/0.1) Chrome/119.0.6045.214 Safari/537.36', 'amzn_searchbot'],
            'amazonbot' => ['Mozilla/5.0 (compatible; Amazonbot/0.1; +https://developer.amazon.com/support/amazonbot)', 'amazonbot'],
            'googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 'googlebot'],
            'google-agent' => ['Mozilla/5.0 (Linux) Chrome/141 Safari/537.36 (compatible; Google-Agent)', 'google_agent'],
            'notebooklm' => ['Google-NotebookLM', 'google_notebook'],
            'perplexity user' => ['Mozilla/5.0 (compatible; Perplexity-User/1.0)', 'perplexity_user'],
            'browser' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 Safari/605.1.15', null],
            'empty' => ['', null],
            'robots-only token is never matched' => ['Google-Extended', null],
        ];
    }

    /**
     * @dataProvider userAgents
     */
    public function testMatch(string $userAgent, ?string $expected): void
    {
        self::assertSame($expected, self::registry()->match($userAgent)?->getId());
    }

    public function testSignatureAgents(): void
    {
        $registry = self::registry();
        self::assertSame('chatgpt_agent', $registry->matchSignatureAgent('chatgpt.com')?->getId());
        self::assertSame('google_agent', $registry->matchSignatureAgent('AGENT.BOT.GOOG')?->getId());
        self::assertNull($registry->matchSignatureAgent('evil.example'));
    }

    public function testMcpClients(): void
    {
        $registry = self::registry();
        self::assertSame(['name' => 'claude-ai', 'operator' => 'Anthropic'], $registry->matchMcpClient('Claude AI'));
        self::assertSame(['name' => 'my-agent', 'operator' => ''], $registry->matchMcpClient('My Agent!'));
        self::assertNull($registry->matchMcpClient('  '));
        self::assertTrue($registry->isTestTool('angeo-agentic-scan'));
        self::assertFalse($registry->isTestTool('claude-ai'));
    }

    public function testReferrersAndUtm(): void
    {
        $registry = self::registry();
        self::assertSame(['source' => 'chatgpt', 'operator' => 'OpenAI'], $registry->matchReferrer('chatgpt.com'));
        self::assertSame('perplexity', $registry->matchReferrer('www.perplexity.ai')['source'] ?? null);
        self::assertNull($registry->matchReferrer('notchatgpt.com'));
        self::assertSame('chatgpt', $registry->matchUtmSource('chatgpt.com'));
        self::assertNull($registry->matchUtmSource('newsletter'));
    }

    public function testDiXmlRowsAddAndOverride(): void
    {
        $registry = self::registry([
            'my_bot' => ['id' => 'my_bot', 'name' => 'MyBot', 'operator' => 'Example', 'category' => 'search', 'ua_tokens' => ['mybot'], 'checked_on' => '2026-10-26'],
            'gptbot' => ['description' => 'Changed here'],
        ]);
        self::assertSame('my_bot', $registry->match('Mozilla/5.0 MyBot/1.0')?->getId());
        self::assertSame('Changed here', $registry->get('gptbot')?->getDescription());
        self::assertSame('OpenAI', $registry->get('gptbot')?->getOperator());
    }

    public function testBrokenFileGivesEmptyListAndALogLine(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'bots');
        file_put_contents($file, '{"schema_version": 1, "bots": [');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');
        $registry = self::registry([], $file, $logger);
        self::assertSame([], $registry->all());
        self::assertNull($registry->match('GPTBot'));
        unlink($file);
    }

    public function testUnknownSchemaVersionIsRefused(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'bots');
        file_put_contents($file, '{"schema_version": 2, "bots": []}');
        self::assertSame([], self::registry([], $file)->all());
        unlink($file);
    }
}
