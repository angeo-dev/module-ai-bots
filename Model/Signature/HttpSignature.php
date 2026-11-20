<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AiBots\Model\Signature;

/**
 * One signature from Signature-Input + Signature (RFC 9421).
 */
class HttpSignature
{
    /**
     * @param array<string, mixed> $definition parsed inner list (components + parameters)
     */
    public function __construct(
        public readonly string $label,
        public readonly array $definition,
        public readonly string $signature
    ) {
    }

    /**
     * Covered components: [['name' => '@authority', 'params' => [...]], …].
     *
     * @return list<array{name: string, params: array<string, array<string, mixed>>}>
     */
    public function components(): array
    {
        $components = [];
        foreach ($this->definition['value'] as $item) {
            $components[] = ['name' => strtolower((string) $item['value']), 'params' => $item['params'] ?? []];
        }
        return $components;
    }

    /**
     * Whether a component is covered; with $key, only "name";key="$key".
     */
    public function covers(string $name, ?string $key = null): bool
    {
        foreach ($this->components() as $component) {
            if ($component['name'] !== strtolower($name)) {
                continue;
            }
            $param = $component['params']['key'] ?? null;
            if ($key === null ? $param === null : ($param !== null && (string) $param['value'] === $key)) {
                return true;
            }
        }
        return false;
    }

    /**
     * String or token parameter, or null.
     */
    public function param(string $name): ?string
    {
        $param = $this->definition['params'][$name] ?? null;
        return $param !== null && in_array($param['type'], ['string', 'token'], true) ? (string) $param['value'] : null;
    }

    /**
     * Integer parameter, or null.
     */
    public function intParam(string $name): ?int
    {
        $param = $this->definition['params'][$name] ?? null;
        return $param !== null && $param['type'] === 'integer' ? (int) $param['value'] : null;
    }
}
