<?php

declare(strict_types=1);

namespace Avsbhq\Avsb;

/**
 * EvalContext: readonly value object wrapping the wire-protocol context
 * array used throughout the PHP SDK.
 *
 * Mirrors the §2.1 EvalContext shape from types.ts. The underlying array
 * is exposed via toArray() for the evaluator; all construction goes through
 * the static factories below.
 */
readonly class EvalContext
{
    private function __construct(private array $context) {}

    /**
     * Construct a single-kind context.
     *
     * @param string               $key        Unique bucketing identifier.
     * @param array<string, mixed> $attributes Targeting attributes.
     * @param string               $kind       Context kind (default: 'user').
     */
    public static function user(
        string $key,
        array $attributes = [],
        string $kind = 'user'
    ): self {
        $ctx = array_merge($attributes, ['kind' => $kind, 'key' => $key]);
        return new self($ctx);
    }

    /**
     * Construct a multi-kind context from an array of single-context arrays
     * keyed by context kind.
     *
     * Example:
     *   EvalContext::multi([
     *     'user'         => ['key' => 'alice', 'plan' => 'pro'],
     *     'organization' => ['key' => 'acme', 'tier' => 'enterprise'],
     *   ]);
     *
     * @param array<string, array<string, mixed>> $contexts  Keyed by kind.
     */
    public static function multi(array $contexts): self
    {
        $result = ['kind' => 'multi'];
        foreach ($contexts as $kind => $attrs) {
            // Ensure 'kind' is set correctly on each sub-context.
            $result[$kind] = array_merge($attrs, ['kind' => $kind]);
            if (!isset($result[$kind]['key'])) {
                $result[$kind]['key'] = '';
            }
        }
        return new self($result);
    }

    /**
     * Wrap a raw context array (e.g. decoded from JSON) directly.
     * The array must already conform to the wire-protocol shape.
     */
    public static function fromArray(array $raw): self
    {
        return new self($raw);
    }

    /** Return the underlying context array. */
    public function toArray(): array
    {
        return $this->context;
    }

    /** True when this is a multi-context container. */
    public function isMulti(): bool
    {
        return ($this->context['kind'] ?? null) === 'multi';
    }
}
