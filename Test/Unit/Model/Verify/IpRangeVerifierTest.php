<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Test\Unit\Model\Verify;

use Angeo\AiBots\Api\Data\VerificationResultInterface as Result;
use Angeo\AiBots\Model\Verify\CidrMatcher;
use Angeo\AiBots\Model\Verify\IpRangeVerifier;
use Angeo\AiBots\Model\Verify\StoredLists;
use Angeo\AiBots\Test\Unit\Model\BotRegistryTest;
use PHPUnit\Framework\TestCase;

class IpRangeVerifierTest extends TestCase
{
    private function verifier(?array $prefixes): IpRangeVerifier
    {
        $lists = $this->createMock(StoredLists::class);
        $lists->method('prefixes')->willReturn($prefixes);
        return new IpRangeVerifier($lists, new CidrMatcher());
    }

    /**
     * @return array<string, array{0: array<int, string>|null, 1: string, 2: string}>
     */
    public static function cases(): array
    {
        $list = ['20.171.206.0/24', '2a01:111:f400::/48'];
        return [
            'in the list' => [$list, '20.171.206.17', Result::STATE_VERIFIED],
            'ipv6 in the list' => [$list, '2a01:111:f400:1::5', Result::STATE_VERIFIED],
            'ipv4-mapped ipv6' => [$list, '::ffff:20.171.206.17', Result::STATE_VERIFIED],
            'not in the list' => [$list, '8.8.8.8', Result::STATE_INVALID],
            'private address' => [$list, '10.0.0.5', Result::STATE_BEHIND_PROXY],
            'loopback' => [$list, '127.0.0.1', Result::STATE_BEHIND_PROXY],
            'list not downloaded' => [null, '20.171.206.17', Result::STATE_UNVERIFIED],
            'no address' => [$list, '', Result::STATE_UNVERIFIED],
        ];
    }

    /**
     * @dataProvider cases
     * @param array<int, string>|null $prefixes
     */
    public function testStates(?array $prefixes, string $ip, string $state): void
    {
        $bot = BotRegistryTest::registry()->get('gptbot');
        self::assertNotNull($bot);
        self::assertSame($state, $this->verifier($prefixes)->verify($bot, $ip)->getState());
    }

    public function testBotWithoutListIsUnsupported(): void
    {
        $bot = BotRegistryTest::registry()->get('ccbot');
        self::assertNotNull($bot);
        $result = $this->verifier(['0.0.0.0/0'])->verify($bot, '1.2.3.4');
        self::assertSame(Result::STATE_UNSUPPORTED, $result->getState());
    }

    public function testSharedListSaysSo(): void
    {
        $bot = BotRegistryTest::registry()->get('claudebot');
        self::assertNotNull($bot);
        $result = $this->verifier(['160.79.104.0/23'])->verify($bot, '160.79.104.10');
        self::assertTrue($result->isVerified());
        self::assertTrue($result->getDetails()['shared']);
    }
}
