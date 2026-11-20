<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Model\Signature;

/**
 * Signature-Input and Signature headers => HttpSignature list (RFC 9421 section 4).
 */
class SignatureParser
{
    /** More signatures than this in one request are ignored. */
    public const MAX_SIGNATURES = 5;

    public function __construct(
        private readonly StructuredFields $fields
    ) {
    }

    /**
     * Signatures present in both headers, in Signature-Input order.
     *
     * @return HttpSignature[]
     */
    public function parse(string $signatureInput, string $signature): array
    {
        $inputs = $this->fields->parseDictionary(trim($signatureInput));
        $values = $this->fields->parseDictionary(trim($signature));
        if ($inputs === null || $values === null) {
            return [];
        }
        $result = [];
        foreach ($inputs as $label => $definition) {
            $value = $values[$label] ?? null;
            if ($definition['type'] !== 'inner' || $value === null || $value['type'] !== 'bytes') {
                continue;
            }
            foreach ($definition['value'] as $component) {
                if ($component['type'] !== 'string') {
                    continue 2;
                }
            }
            $result[] = new HttpSignature((string) $label, $definition, (string) $value['value']);
            if (count($result) >= self::MAX_SIGNATURES) {
                break;
            }
        }
        return $result;
    }
}
