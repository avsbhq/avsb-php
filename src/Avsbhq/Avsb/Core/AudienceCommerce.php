<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Core;

/**
 * Commerce audience conditions: CART_VALUE, PRODUCTS_VIEWED, PURCHASE_HISTORY.
 *
 * Port of packages/avsb-core/src/audienceCommerce.ts. Attributes are resolved
 * by the caller (Audience) through the same kinded lookup CUSTOM_ATTRIBUTE
 * uses, so this class receives a resolver callable and stays free of
 * context-shape concerns.
 *
 * Every "cannot know" path evaluates to false: a missing or mistyped
 * attribute, a non-numeric condition value, dataset-backed membership, or an
 * unknown operator. Nothing here throws.
 *
 * An e-commerce audience built in the dashboard used to evaluate false on
 * every server SDK with no warning; these operators are the fix, and the
 * operator_commerce_* conformance cases lock them.
 *
 * @internal
 */
final class AudienceCommerce
{
    private const DEFAULT_CURRENCY_EXPONENT = 2;
    private const MS_PER_DAY = 86_400_000;

    /** The three condition types this class owns. */
    public static function handles(?string $type): bool
    {
        return $type === 'CART_VALUE'
            || $type === 'PRODUCTS_VIEWED'
            || $type === 'PURCHASE_HISTORY';
    }

    /**
     * Evaluate one commerce condition.
     *
     * @param array<string, mixed> $condition        the datafile condition
     * @param callable(string): mixed $getAttribute  resolves an attribute name
     * @param int|null $currencyExponent             datafile currencyExponent
     */
    public static function evaluate(
        array $condition,
        callable $getAttribute,
        ?int $currencyExponent = null
    ): bool {
        $exponent = self::exponent($currencyExponent);

        return match ($condition['type'] ?? '') {
            'CART_VALUE' => self::cartValue($condition, $getAttribute, $exponent),
            'PRODUCTS_VIEWED' => self::productsViewed($condition, $getAttribute),
            'PURCHASE_HISTORY' => self::purchaseHistory($condition, $getAttribute, $exponent),
            default => false,
        };
    }

    // ------------------------------------------------------------------
    // CART_VALUE
    // ------------------------------------------------------------------

    /** @param array<string, mixed> $condition */
    private static function cartValue(
        array $condition,
        callable $getAttribute,
        int $exponent
    ): bool {
        $actual = $getAttribute('cartValue');
        // cartValue is a decimal major-units number. Anything else is mistyped
        // and fails closed, a numeric STRING included: the caller owns this
        // attribute, so a string means the wrong data reached us.
        if (!self::isFiniteNumber($actual)) {
            return false;
        }
        $cart = (float) $actual;
        $operator = $condition['operator'] ?? '';
        $rawValue = $condition['value'] ?? null;

        if ($operator === 'between') {
            if (!is_array($rawValue) || count($rawValue) < 2) {
                return false;
            }
            $lower = self::minorToMajor($rawValue[0] ?? null, $exponent);
            $upper = self::minorToMajor($rawValue[1] ?? null, $exponent);
            if ($lower === null || $upper === null) {
                return false;
            }

            // between is inclusive on both ends.
            return $cart >= $lower && $cart <= $upper;
        }

        $threshold = self::minorToMajor(self::singleValue($condition), $exponent);
        if ($threshold === null) {
            return false;
        }

        return match ($operator) {
            'greater_than' => $cart > $threshold,
            'gte' => $cart >= $threshold,
            'less_than' => $cart < $threshold,
            'lte' => $cart <= $threshold,
            default => false,
        };
    }

    // ------------------------------------------------------------------
    // PRODUCTS_VIEWED
    // ------------------------------------------------------------------

    /** @param array<string, mixed> $condition */
    private static function productsViewed(array $condition, callable $getAttribute): bool
    {
        // Dataset-backed membership cannot be resolved server-side: dataset
        // windows live in the browser. Both operators evaluate false, because
        // degrading to false beats an accidental full-traffic match.
        $datasetSlug = $condition['datasetSlug'] ?? null;
        if (is_string($datasetSlug) && $datasetSlug !== '') {
            return false;
        }

        // `match` picks which attribute array is consulted. `window` is
        // ignored server-side: the caller supplies whatever view list it
        // considers current.
        $attributeName = ($condition['match'] ?? null) === 'category'
            ? 'viewedCategories'
            : 'viewedSkus';
        $actual = $getAttribute($attributeName);
        if (!is_array($actual)) {
            return false;
        }
        $viewed = array_values(array_filter($actual, 'is_string'));

        $rawValue = $condition['value'] ?? null;
        $list = is_array($rawValue) ? $rawValue : [$rawValue];
        $targets = array_values(array_filter(
            $list,
            static fn (mixed $v): bool => is_string($v) && $v !== ''
        ));
        // No membership source: both operators false.
        if ($targets === []) {
            return false;
        }

        $anyViewed = false;
        foreach ($targets as $target) {
            if (in_array($target, $viewed, true)) {
                $anyViewed = true;
                break;
            }
        }

        return match ($condition['operator'] ?? '') {
            'viewed_any' => $anyViewed,
            'not_viewed_any' => !$anyViewed,
            default => false,
        };
    }

    // ------------------------------------------------------------------
    // PURCHASE_HISTORY
    // ------------------------------------------------------------------

    /** @param array<string, mixed> $condition */
    private static function purchaseHistory(
        array $condition,
        callable $getAttribute,
        int $exponent
    ): bool {
        $raw = $getAttribute('purchaseHistory');
        // A completely absent purchaseHistory makes ALL operators false,
        // including has_not_purchased. Server callers own their data: absence
        // means "not provided", not "never purchased". Mistyped values fail
        // closed the same way.
        if (!is_array($raw) || array_is_list($raw)) {
            return false;
        }
        $hasPurchased = $raw['hasPurchased'] ?? null;
        if (!is_bool($hasPurchased)) {
            return false;
        }

        switch ($condition['operator'] ?? '') {
            case 'has_purchased':
                return $hasPurchased;

            case 'has_not_purchased':
                return !$hasPurchased;

            case 'last_purchase_older_than':
                $days = self::numericValue(self::singleValue($condition));
                if ($days === null) {
                    return false;
                }
                $lastPurchaseAt = $raw['lastPurchaseAt'] ?? null;
                if (self::isFiniteNumber($lastPurchaseAt)) {
                    return (float) $lastPurchaseAt < self::nowMs() - ($days * self::MS_PER_DAY);
                }

                // No usable purchase timestamp: vacuously older only when the
                // visitor has never purchased. A purchaser with an unknown
                // timestamp is a "cannot know" and evaluates false.
                return !$hasPurchased;

            case 'lifetime_spend_gt':
                $threshold = self::minorToMajor(self::singleValue($condition), $exponent);
                if ($threshold === null) {
                    return false;
                }
                $spend = $raw['lifetimeSpend'] ?? null;

                return self::isFiniteNumber($spend) && (float) $spend > $threshold;

            case 'order_count_gt':
                $minimum = self::numericValue(self::singleValue($condition));
                if ($minimum === null) {
                    return false;
                }
                $count = $raw['orderCount'] ?? null;

                return self::isFiniteNumber($count) && (float) $count > $minimum;

            default:
                return false;
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Milliseconds since the Unix epoch. A seam so a test can pin the clock
     * for last_purchase_older_than.
     */
    public static function nowMs(): float
    {
        return round(microtime(true) * 1000);
    }

    private static function exponent(?int $currencyExponent): int
    {
        if ($currencyExponent !== null && $currencyExponent >= 0) {
            return $currencyExponent;
        }

        return self::DEFAULT_CURRENCY_EXPONENT;
    }

    /**
     * Money on a condition is a minor-units integer STRING (contract C1). A
     * non-string, or a string that is not a number, means the condition cannot
     * be evaluated at all.
     */
    private static function minorToMajor(mixed $raw, int $exponent): ?float
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $minor = JsSemantics::toNumber($raw);
        if ($minor === null || is_infinite($minor)) {
            return null;
        }

        return $minor / (10 ** $exponent);
    }

    /** Plain numeric condition values (days, counts) are also strings. */
    private static function numericValue(mixed $raw): ?float
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $value = JsSemantics::toNumber($raw);
        if ($value === null || is_infinite($value)) {
            return null;
        }

        return $value;
    }

    private static function isFiniteNumber(mixed $value): bool
    {
        if (is_int($value)) {
            return true;
        }

        return is_float($value) && !is_nan($value) && !is_infinite($value);
    }

    /** @param array<string, mixed> $condition */
    private static function singleValue(array $condition): mixed
    {
        $raw = $condition['value'] ?? null;

        return is_array($raw) ? ($raw[0] ?? null) : $raw;
    }
}
