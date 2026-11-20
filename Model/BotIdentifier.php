<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Model;

use Angeo\AiBots\Api\BotIdentifierInterface;
use Angeo\AiBots\Api\BotRegistryInterface;
use Angeo\AiBots\Api\Data\IdentityInterface;
use Angeo\AiBots\Api\Data\IncomingRequestInterface;
use Angeo\AiBots\Model\Signature\SignatureAgentHeader;

/**
 * Who the request says it is, from headers only.
 *
 *  1. A known token in User-Agent wins ("ChatGPT-User/1.0" => chatgpt_user).
 *  2. Otherwise a Signature-Agent host in the list names the agent
 *     (Chrome user agent + "https://chatgpt.com" => chatgpt_agent).
 *  3. Otherwise a signed request is an unlisted signed agent, named by its
 *     host, category "other" (ad verification, monitoring).
 *  4. Otherwise "unknown".
 *
 * A request counts as signed only with both Signature-Agent and Signature.
 */
class BotIdentifier implements BotIdentifierInterface
{
    public function __construct(
        private readonly BotRegistryInterface $registry,
        private readonly SignatureAgentHeader $signatureAgentHeader
    ) {
    }

    /**
     * @inheritDoc
     */
    public function identify(IncomingRequestInterface $request): IdentityInterface
    {
        $hosts = [];
        $agentHeader = (string) $request->getHeader('signature-agent');
        if ($agentHeader !== '' && (string) $request->getHeader('signature') !== '') {
            $hosts = $this->signatureAgentHeader->hosts($agentHeader);
        }
        $signed = $hosts !== [];
        $origin = $signed ? 'https://' . $hosts[0] : null;

        $bot = $this->registry->match((string) $request->getHeader('user-agent'));
        if ($bot !== null) {
            return new Identity(
                IdentityInterface::KIND_BOT,
                $bot,
                $bot->getId(),
                $bot->getOperator(),
                $bot->getCategory(),
                $signed,
                $origin
            );
        }

        foreach ($hosts as $host) {
            $bot = $this->registry->matchSignatureAgent($host);
            if ($bot !== null) {
                return new Identity(
                    IdentityInterface::KIND_SIGNED_AGENT,
                    $bot,
                    $bot->getId(),
                    $bot->getOperator(),
                    $bot->getCategory(),
                    true,
                    'https://' . $host
                );
            }
        }

        if ($signed) {
            return new Identity(
                IdentityInterface::KIND_SIGNED_AGENT,
                null,
                $hosts[0],
                self::operatorFromHost($hosts[0]),
                Identity::CATEGORY_OTHER,
                true,
                $origin
            );
        }

        return new Identity(
            IdentityInterface::KIND_UNKNOWN,
            null,
            'unknown',
            '',
            Identity::CATEGORY_UNKNOWN,
            false,
            null
        );
    }

    /**
     * "crawler-key.proximic.com" => "proximic.com".
     */
    private static function operatorFromHost(string $host): string
    {
        $parts = explode('.', $host);
        return count($parts) > 2 ? implode('.', array_slice($parts, -2)) : $host;
    }
}
