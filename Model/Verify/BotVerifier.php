<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Model\Verify;

use Angeo\AiBots\Api\BotVerifierInterface;
use Angeo\AiBots\Api\Data\IdentityInterface;
use Angeo\AiBots\Api\Data\IncomingRequestInterface;
use Angeo\AiBots\Api\Data\VerificationResultInterface as Result;

/**
 * Picks the proof the request offers.
 *
 *  1. Signed request: Web Bot Auth. A valid or a broken signature decides.
 *  2. Otherwise, or when the signature cannot be checked here: the IP list.
 *  3. If neither applies: the result of step 1, or "unsupported".
 */
class BotVerifier implements BotVerifierInterface
{
    public function __construct(
        private readonly WebBotAuthVerifier $webBotAuth,
        private readonly IpRangeVerifier $ipRanges
    ) {
    }

    /**
     * @inheritDoc
     */
    public function verify(IdentityInterface $identity, IncomingRequestInterface $request): Result
    {
        $signed = null;
        if ($identity->isSigned()) {
            $signed = $this->webBotAuth->verify($identity, $request);
            if ($signed->isVerified() || $signed->isInvalid()) {
                return $signed;
            }
        }

        $bot = $identity->getBot();
        if ($bot !== null && $bot->getIpRangesUrl() !== null) {
            $byIp = $this->ipRanges->verify($bot, $request->getRemoteIp());
            if ($byIp->getState() !== Result::STATE_UNVERIFIED || $signed === null) {
                return $byIp;
            }
        }

        return $signed ?? VerificationResult::of(
            Result::STATE_UNSUPPORTED,
            Result::METHOD_NONE,
            $bot === null
                ? 'The request names no known bot.'
                : sprintf('%s publishes no way to check %s.', $bot->getOperator(), $bot->getName())
        );
    }
}
