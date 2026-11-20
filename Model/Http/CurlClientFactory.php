<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Model\Http;

use Magento\Framework\HTTP\Client\Curl;

/**
 * A new Magento Curl client per request, so no option or header leaks from
 * one download into the next.
 */
class CurlClientFactory
{
    /**
     * New client.
     */
    public function create(): Curl
    {
        return new Curl();
    }
}
