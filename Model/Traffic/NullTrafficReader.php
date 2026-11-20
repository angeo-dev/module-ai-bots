<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Model\Traffic;

use Angeo\AiBots\Api\BotTrafficReaderInterface;
use Angeo\AiBots\Api\Data\TrafficSummaryInterface;

/**
 * Default reader: no recorder installed, so no data.
 * angeo/module-agent-activity replaces it with a di.xml preference.
 */
class NullTrafficReader implements BotTrafficReaderInterface
{
    /**
     * @inheritDoc
     */
    public function summary(int $storeId, string $from, string $to): TrafficSummaryInterface
    {
        return TrafficSummary::empty($from, $to);
    }
}
