<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Core;

/**
 * Context helpers: PHP port of context.ts from @avsbhq/core.
 *
 * Operates on plain arrays that mirror the EvalContext wire shape:
 *   - Single context:  ['kind' => 'user', 'key' => 'alice', ...]
 *   - Multi-context:   ['kind' => 'multi', 'user' => [...], 'org' => [...]]
 */
final class Context
{
    private const RESERVED = ['kind', 'key', 'name', '_meta'];

    /** True when the context array uses kind='multi'. */
    public static function isMultiContext(array $ctx): bool
    {
        return isset($ctx['kind']) && $ctx['kind'] === 'multi';
    }

    /**
     * Return the sub-context array for $kind, or null when absent.
     *
     * For a single context, returns the context itself when its kind matches.
     */
    public static function getContextByKind(array $ctx, string $kind): ?array
    {
        if (self::isMultiContext($ctx)) {
            $candidate = $ctx[$kind] ?? null;
            if (!is_array($candidate)) {
                return null;
            }
            return ($candidate['kind'] ?? null) === $kind ? $candidate : null;
        }
        return ($ctx['kind'] ?? null) === $kind ? $ctx : null;
    }

    /**
     * Return the bucketing key for the given kind (defaults to 'user').
     * Returns '' when the kind is absent.
     */
    public static function bucketingKey(array $ctx, string $kind = 'user'): string
    {
        $single = self::getContextByKind($ctx, $kind);
        return $single !== null ? (string) ($single['key'] ?? '') : '';
    }

    /**
     * Return an array of ['kind' => ..., 'key' => ...] pairs.
     * Attributes are omitted because they may carry PII.
     */
    public static function contextSummary(array $ctx): array
    {
        if (self::isMultiContext($ctx)) {
            $out = [];
            foreach ($ctx as $k => $v) {
                if ($k === 'kind') {
                    continue;
                }
                if (!is_array($v)) {
                    continue;
                }
                $out[] = ['kind' => $v['kind'] ?? $k, 'key' => (string) ($v['key'] ?? '')];
            }
            return $out;
        }
        return [['kind' => $ctx['kind'] ?? 'user', 'key' => (string) ($ctx['key'] ?? '')]];
    }

    /**
     * Resolve a dotted attribute path against a context array.
     *
     *   - 'user.key'           → key of the 'user' sub-context
     *   - 'organization.tier'  → tier attribute on the 'organization' sub-context
     *   - 'userId' (no dot)    → legacy fallback: primary context's key
     *   - other non-dotted key → primary context's attribute bag
     *
     * Returns null when the path cannot be resolved (mirrors TS 'undefined').
     */
    public static function resolveDottedAttribute(array $ctx, string $dottedPath): mixed
    {
        if ($dottedPath === '') {
            return null;
        }

        $dotIdx = strpos($dottedPath, '.');
        if ($dotIdx !== false && $dotIdx > 0) {
            $kind = substr($dottedPath, 0, $dotIdx);
            $attr = substr($dottedPath, $dotIdx + 1);
            $sub  = self::getContextByKind($ctx, $kind);
            if ($sub === null) {
                return null;
            }
            return self::readSubContextAttribute($sub, $attr);
        }

        // Non-dotted path — legacy v1 lookup against the primary context.
        $primary = self::primaryContext($ctx);
        if ($primary === null) {
            return null;
        }
        if ($dottedPath === 'userId') {
            return $primary['key'] ?? null;
        }
        return self::readSubContextAttribute($primary, $dottedPath);
    }

    /**
     * Read a single attribute from a sub-context array, honouring reserved
     * keys 'key' and 'name' as aliases for the built-in fields.
     */
    public static function readSubContextAttribute(array $sub, string $attribute): mixed
    {
        if ($attribute === 'key') {
            return $sub['key'] ?? null;
        }
        if ($attribute === 'name') {
            return $sub['name'] ?? null;
        }
        if (in_array($attribute, self::RESERVED, true)) {
            return null;
        }
        return $sub[$attribute] ?? null;
    }

    /**
     * Return the primary single-context for an EvalContext array.
     * For multi-context, prefers 'user', then the first non-meta entry.
     */
    public static function primaryContext(array $ctx): ?array
    {
        if (!self::isMultiContext($ctx)) {
            return $ctx;
        }
        $user = self::getContextByKind($ctx, 'user');
        if ($user !== null) {
            return $user;
        }
        foreach ($ctx as $k => $v) {
            if ($k === 'kind') {
                continue;
            }
            if (is_array($v)) {
                return $v;
            }
        }
        return null;
    }

    /**
     * Collect private attribute keys declared in _meta across all sub-contexts.
     *
     * @return string[]
     */
    public static function privateAttributesFrom(array $ctx): array
    {
        $out = [];
        $collect = static function (array $sub) use (&$out): void {
            $meta = $sub['_meta'] ?? null;
            if (is_array($meta)) {
                $priv = $meta['privateAttributes'] ?? [];
                foreach ((array) $priv as $key) {
                    $out[] = (string) $key;
                }
            }
        };

        if (self::isMultiContext($ctx)) {
            foreach ($ctx as $k => $v) {
                if ($k === 'kind' || !is_array($v)) {
                    continue;
                }
                $collect($v);
            }
        } else {
            $collect($ctx);
        }

        return array_unique($out);
    }
}
