<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Core;

/**
 * Audience evaluator — PHP port of audience.ts from @avsbhq/core.
 *
 * Only server-evaluable condition types are implemented here:
 * CUSTOM_ATTRIBUTE, and multi-context-aware kinded lookups.
 * Browser-only types (DEVICE, BROWSER, PLATFORM, LANGUAGE, COOKIE,
 * QUERY_PARAM) always return false in a server context.
 */
final class Audience
{
    /**
     * Evaluate an audience array against the context.
     *
     * @param array $audience  Decoded FlagDatafileAudience (has 'id' and 'rules').
     * @param array $context   EvalContext array (single or multi).
     */
    public static function evaluateAudience(array $audience, array $context): bool
    {
        if (empty($audience) || !isset($audience['rules'])) {
            return true;
        }
        return self::evaluateGroup($audience['rules'], $context);
    }

    /**
     * Evaluate a ConditionGroup (has 'logic' and 'conditions').
     */
    public static function evaluateGroup(array $group, array $context): bool
    {
        if (empty($group['conditions']) || !is_array($group['conditions'])) {
            return true;
        }

        $logic = $group['logic'] ?? 'AND';

        if ($logic === 'AND') {
            foreach ($group['conditions'] as $item) {
                $result = self::isConditionGroup($item)
                    ? self::evaluateGroup($item, $context)
                    : self::evaluateCondition($item, $context);
                if (!$result) {
                    return false;
                }
            }
            return true;
        }

        // OR
        foreach ($group['conditions'] as $item) {
            $result = self::isConditionGroup($item)
                ? self::evaluateGroup($item, $context)
                : self::evaluateCondition($item, $context);
            if ($result) {
                return true;
            }
        }
        return false;
    }

    /**
     * Evaluate a single Condition against the context.
     */
    public static function evaluateCondition(array $condition, array $context): bool
    {
        $type = $condition['type'] ?? '';
        return match ($type) {
            'CUSTOM_ATTRIBUTE' => self::evalCustomAttribute($condition, $context),
            // Browser-only conditions are not evaluable server-side.
            'DEVICE', 'BROWSER', 'PLATFORM', 'LANGUAGE', 'COOKIE', 'QUERY_PARAM' => false,
            default => false,
        };
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    private static function isConditionGroup(mixed $item): bool
    {
        return is_array($item) && isset($item['logic'], $item['conditions']);
    }

    private static function evalCustomAttribute(array $condition, array $context): bool
    {
        $attrKey = $condition['attribute'] ?? null;
        if ($attrKey === null || $attrKey === '') {
            return false;
        }

        $contextKind = $condition['contextKind'] ?? null;

        // When a contextKind is declared and we have a multi/kinded context,
        // the sub-context must exist for the condition to be evaluable.
        if ($contextKind !== null && Context::isMultiContext($context)) {
            $sub = Context::getContextByKind($context, $contextKind);
            if ($sub === null) {
                return false;
            }
        }

        $actual   = self::resolveAttribute($context, $contextKind, $attrKey);
        $operator = $condition['operator'] ?? '';
        $rawValue = $condition['value'] ?? '';
        $expected = is_array($rawValue) ? ($rawValue[0] ?? '') : $rawValue;

        return match ($operator) {
            'exists'       => $actual !== null,
            'not_exists'   => $actual === null,
            'equals'       => (string) $actual === (string) $expected,
            'not_equals'   => (string) $actual !== (string) $expected,
            'contains'     => str_contains(
                strtolower((string) $actual),
                strtolower((string) $expected)
            ),
            'not_contains' => !str_contains(
                strtolower((string) $actual),
                strtolower((string) $expected)
            ),
            'starts_with'  => str_starts_with(
                strtolower((string) $actual),
                strtolower((string) $expected)
            ),
            'ends_with'    => str_ends_with(
                strtolower((string) $actual),
                strtolower((string) $expected)
            ),
            'regex' => self::matchesRegex((string) $actual, (string) $expected),
            'greater_than' => (float) $actual > (float) $expected,
            'less_than'    => (float) $actual < (float) $expected,
            'gte'          => (float) $actual >= (float) $expected,
            'lte'          => (float) $actual <= (float) $expected,
            'in_list' => (function () use ($actual, $rawValue): bool {
                $list = is_array($rawValue) ? $rawValue : [$rawValue];
                foreach ($list as $v) {
                    if ((string) $actual === (string) $v) {
                        return true;
                    }
                }
                return false;
            })(),
            'not_in_list' => (function () use ($actual, $rawValue): bool {
                $list = is_array($rawValue) ? $rawValue : [$rawValue];
                foreach ($list as $v) {
                    if ((string) $actual === (string) $v) {
                        return false;
                    }
                }
                return true;
            })(),
            default => false,
        };
    }

    /**
     * Resolve (contextKind, attribute) from either a single or multi context.
     *
     * When contextKind is null, falls back to the primary context's attribute bag.
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

        // Legacy single-context fallback.
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

    private static function matchesRegex(string $actual, string $pattern): bool
    {
        try {
            $result = @preg_match('/' . $pattern . '/i', $actual);
            return $result === 1;
        } catch (\Throwable) {
            return false;
        }
    }
}
