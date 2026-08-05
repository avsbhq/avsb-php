<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Core;

/**
 * JavaScript value semantics, implemented deliberately instead of PHP's.
 *
 * The reference evaluator is the TypeScript engine in @avsbhq/core, so
 * JavaScript's rules are the cross-language contract
 * (packages/avsb-conformance/fixtures/semantics/manifest.json). PHP disagrees
 * with JavaScript in three places that would each change what a flag answers:
 *
 *  - Truthiness. PHP calls the STRING "0" false, and calls an empty array
 *    false. JavaScript calls both truthy.
 *  - Numeric coercion. PHP's (float) cast turns "abc" into 0.0. JavaScript's
 *    Number("abc") is NAN, and every comparison against NAN is false.
 *  - Float printing. PHP's string cast honours the `precision` ini setting,
 *    which can round a threshold in a reason string the corpus compares
 *    exactly.
 *
 * Nothing in this SDK may use a bare (bool) cast on a flag value or a (float)
 * cast on an audience attribute. Use these helpers.
 *
 * @internal
 */
final class JsSemantics
{
    /**
     * Values JavaScript treats as falsy: false, 0, -0, NAN, "", null,
     * undefined. Everything else is truthy, including "0", "false", [], and
     * an empty object.
     */
    public static function isTruthy(mixed $value): bool
    {
        if ($value === null || $value === false) {
            return false;
        }
        if ($value === true) {
            return true;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_float($value)) {
            return !is_nan($value) && $value !== 0.0;
        }
        if (is_string($value)) {
            // "0" is truthy here, unlike in PHP. Do not parse flag values.
            return $value !== '';
        }

        // Arrays and objects, including empty ones, are truthy.
        return true;
    }

    /**
     * JavaScript's Number(value), for audience comparison operators.
     *
     * Returns null where JavaScript returns NAN, because every comparison
     * against NAN is false and null forces the caller to say so explicitly.
     *
     * Deliberate choice: a missing attribute arrives here as null. JavaScript
     * distinguishes undefined (NAN) from null (0); PHP has one null, and
     * treating it as NAN is the fail-closed reading, so a rule comparing a
     * missing attribute never matches.
     */
    public static function toNumber(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }
        if ($value === true) {
            return 1.0;
        }
        if ($value === false) {
            return 0.0;
        }
        if (is_int($value)) {
            return (float) $value;
        }
        if (is_float($value)) {
            return is_nan($value) ? null : $value;
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return 0.0; // Number("") is 0 in JavaScript.
            }
            if ($trimmed === 'Infinity' || $trimmed === '+Infinity') {
                return INF;
            }
            if ($trimmed === '-Infinity') {
                return -INF;
            }

            return is_numeric($trimmed) ? (float) $trimmed : null;
        }

        // Arrays and objects are not numbers for our purposes.
        return null;
    }

    /**
     * Both sides as numbers, or null when either is not a number.
     *
     * @return array{0: float, 1: float}|null
     */
    public static function numberPair(mixed $left, mixed $right): ?array
    {
        $a = self::toNumber($left);
        if ($a === null) {
            return null;
        }
        $b = self::toNumber($right);
        if ($b === null) {
            return null;
        }

        return [$a, $b];
    }

    /**
     * JavaScript's String(number), used for reason strings.
     *
     * The conformance corpus records reason text exactly, including threshold
     * numbers: "threshold 1000" and "threshold 1234.5". A plain string cast
     * would be at the mercy of the `precision` ini setting, so integral values
     * print as integers and everything else round-trips through json_encode,
     * which uses the shortest representation that reads back identically.
     */
    public static function numberToString(int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_nan($value)) {
            return 'NaN';
        }
        if (is_infinite($value)) {
            return $value > 0 ? 'Infinity' : '-Infinity';
        }
        // 1e21 is where JavaScript switches to exponential notation; below it
        // an integral double prints as a plain integer.
        if ($value === floor($value) && abs($value) < 1e21) {
            return (string) (int) $value;
        }

        $encoded = json_encode($value);

        return $encoded === false ? (string) $value : $encoded;
    }
}
