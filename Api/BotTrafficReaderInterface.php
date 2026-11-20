<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Api;

use Angeo\AiBots\Api\Data\TrafficSummaryInterface;

/**
 * Read access to recorded AI bot traffic.
 *
 * This module ships an empty implementation (no data). A module that records
 * traffic (angeo/module-agent-activity) replaces it with a di.xml preference.
 * So a reader such as angeo/module-aeo-audit depends only on this module and
 * keeps working when the recorder is not installed.
 *
 * @api
 * @since 1.0.0
 */
interface BotTrafficReaderInterface
{
    /**
     * @param int $storeId store view id, 0 for all stores
     * @param string $from first day, Y-m-d
     * @param string $to last day, Y-m-d
     */
    public function summary(int $storeId, string $from, string $to): TrafficSummaryInterface;
}
