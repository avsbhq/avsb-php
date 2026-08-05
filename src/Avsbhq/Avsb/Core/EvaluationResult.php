<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Core;

/**
 * Building blocks shared by the evaluator and its rule branches.
 *
 * Extracted so Evaluator, AbTestRule, and BanditDispatch produce byte-identical
 * result shapes and reason text without any of them owning a private copy.
 *
 * @internal
 */
final class EvaluationResult
{
    /**
     * The evaluator's return shape, in one place.
     *
     * @param list<string> $reasons
     * @return array{
     *   value: mixed,
     *   variationKey: string|null,
     *   source: string,
     *   ruleId: string|null,
     *   ruleType: string|null,
     *   flagKey: string,
     *   reasons: list<string>
     * }
     */
    public static function make(
        mixed $value,
        ?string $variationKey,
        string $source,
        ?string $ruleId,
        ?string $ruleType,
        string $flagKey,
        array $reasons
    ): array {
        return [
            'value' => $value,
            'variationKey' => $variationKey,
            'source' => $source,
            'ruleId' => $ruleId,
            'ruleType' => $ruleType,
            'flagKey' => $flagKey,
            'reasons' => $reasons,
        ];
    }

    /**
     * Look up a variation by id.
     *
     * @param array<string, mixed> $flag
     * @return array{value: mixed, key: string}|null
     */
    public static function variation(array $flag, string $variationId): ?array
    {
        foreach ($flag['variations'] ?? [] as $variation) {
            if (($variation['id'] ?? null) === $variationId) {
                return ['value' => $variation['value'], 'key' => $variation['key']];
            }
        }

        return null;
    }

    /**
     * Reason strings print the variation key the reference prints. When a rule
     * points at a variation the flag no longer carries, the reference
     * interpolates a missing value and JavaScript renders the word
     * "undefined"; matching that keeps the recorded decision trail identical
     * across languages.
     *
     * @param array{value: mixed, key: string}|null $variation
     */
    public static function keyText(?array $variation): string
    {
        return $variation !== null ? $variation['key'] : 'undefined';
    }

    /** Stringify a hash attribute the way the reference String() does. */
    public static function stringify(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return JsSemantics::numberToString($value);
        }
        $encoded = json_encode($value);

        return $encoded === false ? '' : $encoded;
    }

    /** Milliseconds since the Unix epoch, as a number. */
    public static function nowMs(): float
    {
        return round(microtime(true) * 1000);
    }
}
