<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Model\Verify;

use Angeo\AiBots\Api\Data\BotInterface;
use Angeo\AiBots\Api\Data\VerificationResultInterface as Result;
use Angeo\AiBots\Model\Http\IpGuard;

/**
 * Is the client address in the list the operator publishes for this bot?
 */
class IpRangeVerifier
{
    public function __construct(
        private readonly StoredLists $lists,
        private readonly CidrMatcher $matcher
    ) {
    }

    /**
     * Result for this bot and address. Never uses the network.
     */
    public function verify(BotInterface $bot, string $ip): Result
    {
        $url = $bot->getIpRangesUrl();
        if ($url === null) {
            return VerificationResult::of(
                Result::STATE_UNSUPPORTED,
                Result::METHOD_NONE,
                sprintf('%s publishes no IP list for %s.', $bot->getOperator(), $bot->getName())
            );
        }
        $ip = trim($ip);
        $ip = IpGuard::unmapIpv4($ip) ?? $ip;
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return VerificationResult::of(Result::STATE_UNVERIFIED, Result::METHOD_IP_RANGE, 'No client address.');
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            // Magento sees a proxy, not the client. Comparing the proxy address
            // would mark every real bot as spoofed, so give no verdict.
            return VerificationResult::of(
                Result::STATE_BEHIND_PROXY,
                Result::METHOD_IP_RANGE,
                'Magento sees a private address. Set up trusted proxy headers to check bots.'
            );
        }
        $prefixes = $this->lists->prefixes($url);
        if ($prefixes === null || $prefixes === []) {
            return VerificationResult::of(
                Result::STATE_UNVERIFIED,
                Result::METHOD_IP_RANGE,
                'The IP list was not downloaded yet. Run bin/magento angeo:bots:refresh.',
                ['list' => $url]
            );
        }
        if ($this->matcher->containsAny($prefixes, $ip)) {
            return VerificationResult::of(
                Result::STATE_VERIFIED,
                Result::METHOD_IP_RANGE,
                $bot->isIpRangesShared()
                    ? sprintf('Address is in the %s list. The list is shared by several %s bots.', $bot->getOperator(), $bot->getOperator())
                    : sprintf('Address is in the %s list for %s.', $bot->getOperator(), $bot->getName()),
                ['list' => $url, 'shared' => $bot->isIpRangesShared()]
            );
        }
        return VerificationResult::of(
            Result::STATE_INVALID,
            Result::METHOD_IP_RANGE,
            sprintf('Address is not in the %s list for %s: the user agent is probably fake.', $bot->getOperator(), $bot->getName()),
            ['list' => $url]
        );
    }
}
