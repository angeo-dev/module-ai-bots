<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Api;

use Angeo\AiBots\Api\Data\IdentityInterface;
use Angeo\AiBots\Api\Data\IncomingRequestInterface;

/**
 * Says who a request claims to come from. Never uses the network.
 *
 * @api
 * @since 1.0.0
 */
interface BotIdentifierInterface
{
    public function identify(IncomingRequestInterface $request): IdentityInterface;
}
