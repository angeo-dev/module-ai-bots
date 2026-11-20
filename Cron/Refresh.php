<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Cron;

use Angeo\AiBots\Model\Refresher;

/**
 * Daily download of IP lists and signing keys.
 */
class Refresh
{
    public function __construct(
        private readonly Refresher $refresher
    ) {
    }

    /**
     * Run the refresh. Failures are logged and keep the previous copy.
     */
    public function execute(): void
    {
        $this->refresher->refresh();
    }
}
