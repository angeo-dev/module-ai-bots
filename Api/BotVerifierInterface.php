<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Api;

use Angeo\AiBots\Api\Data\IdentityInterface;
use Angeo\AiBots\Api\Data\IncomingRequestInterface;
use Angeo\AiBots\Api\Data\VerificationResultInterface;

/**
 * Checks that a request really comes from the bot it names.
 *
 * Uses only data already stored by the daily refresh (IP lists, signing
 * keys). It never makes a network call, so it is safe on every storefront
 * request. A list or key that was not downloaded yet gives "unverified".
 *
 * @api
 * @since 1.0.0
 */
interface BotVerifierInterface
{
    public function verify(
        IdentityInterface $identity,
        IncomingRequestInterface $request
    ): VerificationResultInterface;
}
