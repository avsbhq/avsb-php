<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Core;

/**
 * Flag evaluator: PHP port of evaluate.ts from @avsbhq/core.
 *
 * Evaluation order (mirrors the TS implementation exactly):
 *   1. Flag not found → not_found
 *   2. Flag disabled  → disabled (serves defaultVariationId value)
 *   3. User override  → override
 *   4. Holdout check  → holdout
 *   5. Rules loop     → rule (targeted_delivery or ab_test, first match)
 *   6. Default        → default
 */
final class Evaluator
{
    /**
     * Evaluate a flag key against the datafile and context.
     *
     * @param string $flagKey
     * @param mixed  $defaultValue  Caller-supplied fallback.
     * @param array  $context       EvalContext array (single or multi).
     * @param array  $datafile      Decoded FlagDatafile array.
     * @param array  $reasons       Mutable reasons accumulator (appended in-place).
     *
     * @return array{
     *   value: mixed,
     *   variationKey: string|null,
     *   source: string,
     *   ruleId: string|null,
     *   ruleType: string|null,
     *   flagKey: string,
     *   reasons: string[]
     * }
     */
    public static function evaluateFlag(
        string $flagKey,
        mixed $defaultValue,
        array $context,
        array $datafile,
        array &$reasons = []
    ): array {
        // 1. FIND FLAG
        $flag = self::findFlag($datafile['flags'] ?? [], $flagKey);
        if ($flag === null) {
            $reasons[] = "flag \"{$flagKey}\" not found in datafile";
            return self::result($defaultValue, null, 'not_found', null, null, $flagKey, $reasons);
        }

        // 2. CHECK ENABLED
        if (!($flag['enabled'] ?? true)) {
            $reasons[] = "flag \"{$flagKey}\" is disabled, returning default variation";
            $defaultVar = self::getVariationValue($flag, $flag['defaultVariationId'] ?? '');
            return self::result(
                $defaultVar !== null ? $defaultVar['value'] : $defaultValue,
                $defaultVar !== null ? $defaultVar['key'] : null,
                'disabled',
                null,
                null,
                $flagKey,
                $reasons
            );
        }

        // 3. CHECK OVERRIDES — matched on the primary context's key.
        $primaryKey = Context::bucketingKey($context, 'user');
        if ($primaryKey === '') {
            $primary    = Context::primaryContext($context);
            $primaryKey = (string) ($primary['key'] ?? '');
        }

        foreach ($flag['overrides'] ?? [] as $override) {
            if (($override['userId'] ?? null) === $primaryKey) {
                $reasons[] = "matched user override for userId=\"{$primaryKey}\"";
                $overrideVar = self::getVariationValue($flag, $override['variationId'] ?? '');
                return self::result(
                    $overrideVar !== null ? $overrideVar['value'] : $defaultValue,
                    $overrideVar !== null ? $overrideVar['key'] : null,
                    'override',
                    null,
                    null,
                    $flagKey,
                    $reasons
                );
            }
        }

        // 4. HOLDOUT CHECK
        $holdoutResult = Holdouts::evaluateHoldout($context, $flag['id'] ?? '', $datafile);
        if ($holdoutResult['heldOut'] && isset($holdoutResult['holdout'])) {
            $holdout    = $holdoutResult['holdout'];
            $targetVarId = $holdoutResult['variationId'] ?? $flag['defaultVariationId'] ?? '';
            $holdoutVar  = self::getVariationValue($flag, $targetVarId);
            $reasons[]   = "held out by holdout \"{$holdout['key']}\", serving default variation \"{$holdoutVar['key'] ?? $targetVarId}\"";
            return self::result(
                $holdoutVar !== null ? $holdoutVar['value'] : $defaultValue,
                $holdoutVar !== null ? $holdoutVar['key'] : null,
                'holdout',
                $holdout['id'],
                'holdout',
                $flagKey,
                $reasons
            );
        }

        // Build audience map for O(1) lookups.
        $audienceMap = [];
        foreach ($datafile['audiences'] ?? [] as $aud) {
            $audienceMap[$aud['id']] = $aud;
        }

        // 5. EVALUATE RULES (first match wins)
        foreach ($flag['rules'] ?? [] as $rule) {
            if (!($rule['enabled'] ?? true)) {
                $reasons[] = "rule \"{$rule['id']}\" skipped (disabled)";
                continue;
            }

            $hashPath  = $rule['hashAttribute'] ?? 'userId';
            $resolved  = Context::resolveDottedAttribute($context, $hashPath);
            $hashValue = ($resolved === null) ? '' : (string) $resolved;

            // a. AUDIENCE CHECK
            $audienceIds = $rule['audienceIds'] ?? [];
            if (!empty($audienceIds)) {
                $matched = false;
                foreach ($audienceIds as $audId) {
                    $audience = $audienceMap[$audId] ?? null;
                    if ($audience !== null && Audience::evaluateAudience($audience, $context)) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    $reasons[] = "rule \"{$rule['id']}\" skipped (audience not matched)";
                    continue;
                }
            }

            // b. EVALUATE BY TYPE
            switch ($rule['type'] ?? '') {
                case 'targeted_delivery':
                    if (empty($rule['variations'])) {
                        continue 2;
                    }
                    $targetVar = self::getVariationValue($flag, $rule['variations'][0]['variationId'] ?? '');
                    $reasons[] = "rule \"{$rule['id']}\" matched (targeted_delivery), serving variation \"{$targetVar['key'] ?? ''}\"";
                    return self::result(
                        $targetVar !== null ? $targetVar['value'] : $defaultValue,
                        $targetVar !== null ? $targetVar['key'] : null,
                        'rule',
                        $rule['id'],
                        'targeted_delivery',
                        $flagKey,
                        $reasons
                    );

                case 'ab_test':
                    // Traffic allocation check.
                    $allocHash = Hash::murmurhash3("{$hashValue}{$flagKey}__{$rule['id']}") % 10000;
                    $trafficAllocation = $rule['trafficAllocation'] ?? null;
                    if ($trafficAllocation !== null) {
                        $threshold = (float) $trafficAllocation * 10000;
                        if ($allocHash >= $threshold) {
                            $reasons[] = "rule \"{$rule['id']}\" excluded by traffic allocation (hash {$allocHash} >= threshold {$threshold})";
                            continue 2;
                        }
                    }

                    // Variation assignment.
                    $hash       = Hash::murmurhash3("{$hashValue}{$flagKey}") % 10000;
                    $cumulative = 0.0;
                    foreach ($rule['variations'] ?? [] as $rv) {
                        $cumulative += (float) ($rv['percentage'] ?? 0) * 10000;
                        $cumulativeInt = (int) $cumulative;
                        if ($hash < $cumulativeInt) {
                            $expVar    = self::getVariationValue($flag, $rv['variationId'] ?? '');
                            $reasons[] = "rule \"{$rule['id']}\" matched (ab_test), bucketed into variation \"{$expVar['key'] ?? ''}\" (hash {$hash}/{$cumulativeInt})";
                            return self::result(
                                $expVar !== null ? $expVar['value'] : $defaultValue,
                                $expVar !== null ? $expVar['key'] : null,
                                'rule',
                                $rule['id'],
                                'ab_test',
                                $flagKey,
                                $reasons
                            );
                        }
                    }
                    continue 2;
            }
        }

        // 6. DEFAULT
        $reasons[] = 'no rules matched, returning default variation';
        $defaultVar = self::getVariationValue($flag, $flag['defaultVariationId'] ?? '');
        return self::result(
            $defaultVar !== null ? $defaultVar['value'] : $defaultValue,
            $defaultVar !== null ? $defaultVar['key'] : null,
            'default',
            null,
            null,
            $flagKey,
            $reasons
        );
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    private static function findFlag(array $flags, string $key): ?array
    {
        foreach ($flags as $flag) {
            if (($flag['key'] ?? null) === $key) {
                return $flag;
            }
        }
        return null;
    }

    /**
     * @return array{value: mixed, key: string}|null
     */
    private static function getVariationValue(array $flag, string $variationId): ?array
    {
        foreach ($flag['variations'] ?? [] as $variation) {
            if (($variation['id'] ?? null) === $variationId) {
                return ['value' => $variation['value'], 'key' => $variation['key']];
            }
        }
        return null;
    }

    private static function result(
        mixed $value,
        ?string $variationKey,
        string $source,
        ?string $ruleId,
        ?string $ruleType,
        string $flagKey,
        array $reasons
    ): array {
        return [
            'value'        => $value,
            'variationKey' => $variationKey,
            'source'       => $source,
            'ruleId'       => $ruleId,
            'ruleType'     => $ruleType,
            'flagKey'      => $flagKey,
            'reasons'      => $reasons,
        ];
    }
}
