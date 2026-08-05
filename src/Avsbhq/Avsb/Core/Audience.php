<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Core;

/**
 * Audience evaluator: port of packages/avsb-core/src/audience.ts.
 *
 * Evaluates CUSTOM_ATTRIBUTE and the three commerce condition types.
 * Browser-detected conditions (DEVICE, BROWSER, PLATFORM, LANGUAGE, COOKIE,
 * QUERY_PARAM) always return false here: this SDK runs on the server, where
 * there is no navigator, no cookie jar, and no query string to read.
 */
final class Audience
{
    /**
     * Evaluate an audience against the context.
     *
     * @param array<string, mixed> $audience decoded FlagDatafileAudience
     * @param array<string, mixed> $context  EvalContext array, single or multi
     * @param int|null $currencyExponent     datafile currencyExponent, used by
     *                                       the money-valued commerce operators
     */
    public static function evaluateAudience(
        array $audience,
        array $context,
        ?int $currencyExponent = null
    ): bool {
        if ($audience === [] || !isset($audience['rules'])) {
            return true;
        }

        return self::evaluateGroup($audience['rules'], $context, $currencyExponent);
    }

    /**
     * Evaluate a ConditionGroup (has 'logic' and 'conditions').
     *
     * @param array<string, mixed> $group
     * @param array<string, mixed> $context
     */
    public static function evaluateGroup(
        array $group,
        array $context,
        ?int $currencyExponent = null
    ): bool {
        if (empty($group['conditions']) || !is_array($group['conditions'])) {
            return true;
        }

        $logic = $group['logic'] ?? 'AND';

        if ($logic === 'AND') {
            foreach ($group['conditions'] as $item) {
                if (!self::evaluateItem($item, $context, $currencyExponent)) {
                    return false;
                }
            }

            return true;
        }

        foreach ($group['conditions'] as $item) {
            if (self::evaluateItem($item, $context, $currencyExponent)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Evaluate a single Condition against the context.
     *
     * @param array<string, mixed> $condition
     * @param array<string, mixed> $context
     */
    public static function evaluateCondition(
        array $condition,
        array $context,
        ?int $currencyExponent = null
    ): bool {
        $type = $condition['type'] ?? '';

        if ($type === 'CUSTOM_ATTRIBUTE') {
            return self::evalCustomAttribute($condition, $context);
        }

        if (AudienceCommerce::handles(is_string($type) ? $type : null)) {
            $contextKind = $condition['contextKind'] ?? null;

            return AudienceCommerce::evaluate(
                $condition,
                static fn (string $attribute): mixed => self::resolveAttribute(
                    $context,
                    is_string($contextKind) ? $contextKind : null,
                    $attribute
                ),
                $currencyExponent
            );
        }

        // Browser-detected and unknown condition types are not evaluable
        // server-side.
        return false;
    }

    // ------------------------------------------------------------------
    // Private
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed>|mixed $item
     * @param array<string, mixed> $context
     */
    private static function evaluateItem(
        mixed $item,
        array $context,
        ?int $currencyExponent
    ): bool {
        if (!is_array($item)) {
            return false;
        }

        return self::isConditionGroup($item)
            ? self::evaluateGroup($item, $context, $currencyExponent)
            : self::evaluateCondition($item, $context, $currencyExponent);
    }

    /** @param array<string, mixed> $item */
    private static function isConditionGroup(array $item): bool
    {
        return isset($item['logic'], $item['conditions']);
    }

    /**
     * @param array<string, mixed> $condition
     * @param array<string, mixed> $context
     */
    private static function evalCustomAttribute(array $condition, array $context): bool
    {
        $attrKey = $condition['attribute'] ?? null;
        if (!is_string($attrKey) || $attrKey === '') {
            return false;
        }

        $contextKind = $condition['contextKind'] ?? null;
        $contextKind = is_string($contextKind) ? $contextKind : null;

        // When the condition names a context kind, that sub-context must exist
        // for the condition to be evaluable at all. A missing kind is a hard
        // no-match, which is NOT the same as a missing attribute (a missing
        // attribute still matches does_not_exist). This guard used to run only
        // for multi-contexts, so a single user context silently satisfied an
        // organization-scoped does_not_exist condition.
        if ($contextKind !== null && Context::getContextByKind($context, $contextKind) === null) {
            return false;
        }

        $actual = self::resolveAttribute($context, $contextKind, $attrKey);
        $operator = $condition['operator'] ?? '';
        $rawValue = $condition['value'] ?? '';
        $expected = is_array($rawValue) ? ($rawValue[0] ?? '') : $rawValue;

        // Alias operators (is, is_not, does_not_contain, does_not_exist) are
        // the names the dashboard audience builder writes for the
        // device/browser condition types, and they reach CUSTOM_ATTRIBUTE
        // conditions too. Before they were implemented, an audience saved with
        // `is` fell through to the default branch and matched nobody.
        return match ($operator) {
            'exists' => $actual !== null,
            'not_exists', 'does_not_exist' => $actual === null,
            'equals', 'is' => self::asString($actual) === self::asString($expected),
            'not_equals', 'is_not' => self::asString($actual) !== self::asString($expected),
            'contains' => str_contains(
                self::lower($actual),
                self::lower($expected)
            ),
            'not_contains', 'does_not_contain' => !str_contains(
                self::lower($actual),
                self::lower($expected)
            ),
            'starts_with' => str_starts_with(self::lower($actual), self::lower($expected)),
            'ends_with' => str_ends_with(self::lower($actual), self::lower($expected)),
            'regex' => self::matchesRegex(self::asString($actual), self::asString($expected)),
            'greater_than' => self::compare($actual, $expected, static fn ($a, $b) => $a > $b),
            'less_than' => self::compare($actual, $expected, static fn ($a, $b) => $a < $b),
            'gte' => self::compare($actual, $expected, static fn ($a, $b) => $a >= $b),
            'lte' => self::compare($actual, $expected, static fn ($a, $b) => $a <= $b),
            'in_list' => self::inList($actual, $rawValue),
            'not_in_list' => !self::inList($actual, $rawValue),
            default => false,
        };
    }

    /**
     * Numeric comparison with JavaScript semantics: when either side is not a
     * number the comparison is false. PHP's (float) cast would turn "abc" into
     * 0.0 and make `greater_than -1` match a text attribute.
     *
     * @param callable(float, float): bool $compare
     */
    private static function compare(mixed $actual, mixed $expected, callable $compare): bool
    {
        $pair = JsSemantics::numberPair($actual, $expected);
        if ($pair === null) {
            return false;
        }

        return $compare($pair[0], $pair[1]);
    }

    private static function inList(mixed $actual, mixed $rawValue): bool
    {
        $list = is_array($rawValue) ? $rawValue : [$rawValue];
        foreach ($list as $candidate) {
            if (self::asString($actual) === self::asString($candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve (contextKind, attribute) from either a single or multi context.
     * With no contextKind, falls back to the primary context's attribute bag.
     *
     * @param array<string, mixed> $context
     */
    private static function resolveAttribute(
        array $context,
        ?string $contextKind,
        string $attribute
    ): mixed {
        if ($contextKind !== null) {
            $sub = Context::getContextByKind($context, $contextKind);
            if ($sub === null) {
                return null;
            }

            return Context::readSubContextAttribute($sub, $attribute);
        }

        $primary = Context::isMultiContext($context)
            ? Context::getContextByKind($context, 'user')
            : $context;

        if ($primary === null) {
            return null;
        }

        if ($attribute === 'userId') {
            return $primary['key'] ?? null;
        }

        return Context::readSubContextAttribute($primary, $attribute);
    }

    /** Stringify for comparison without tripping over arrays or objects. */
    private static function asString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if ($value === null) {
            return '';
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

    private static function lower(mixed $value): string
    {
        return strtolower(self::asString($value));
    }

    /**
     * Test a pattern the dashboard supplied without a delimiter.
     *
     * The delimiter is chosen from candidates the pattern does not contain: a
     * hardcoded "/" broke every pattern with a slash in it, which for URL
     * matching is most of them.
     */
    private static function matchesRegex(string $actual, string $pattern): bool
    {
        foreach (['/', '#', '~', '%', '!', '@'] as $delimiter) {
            if (!str_contains($pattern, $delimiter)) {
                return @preg_match($delimiter . $pattern . $delimiter . 'i', $actual) === 1;
            }
        }

        // Every candidate appears in the pattern: escape the first and use it.
        $escaped = str_replace('/', '\\/', $pattern);

        return @preg_match('/' . $escaped . '/i', $actual) === 1;
    }
}
