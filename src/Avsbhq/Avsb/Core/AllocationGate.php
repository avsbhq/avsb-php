<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Core;

/**
 * The traffic-allocation gate shared by ab_test and targeted_delivery rules.
 * Mirrors allocationExclusion in packages/avsb-core/src/evaluate.ts.
 *
 * One hash input serves both rule types, hashValue . flagKey . '__' . ruleId,
 * so a visitor's rollout membership is the same in every SDK and does not move
 * when a rule changes type.
 *
 * The threshold is a FLOAT comparison against the raw product, never a
 * truncated or rounded integer. A 0.12345 rollout puts the threshold at
 * 1234.5: bucket 1234 is inside it, while a port that truncates to 1234
 * excludes that context and diverges from every other SDK by one bucket in ten
 * thousand. The boundary fixtures lock this at fractional and at integer
 * thresholds, for both rule types.
 *
 * A null allocation means no gate, and so does a field missing from an old
 * datafile.
 *
 * @internal
 */
final class AllocationGate
{
    private const MAX_BUCKET = 10000;

    /**
     * @param array<string, mixed> $rule
     *
     * @return string|null the exclusion reason, character for character the
     *                     reference's, when the context falls outside the
     *                     allocation; null when it is inside or there is no gate
     */
    public static function exclusion(array $rule, string $flagKey, string $hashValue): ?string
    {
        $trafficAllocation = $rule['trafficAllocation'] ?? null;
        if ($trafficAllocation === null) {
            return null;
        }

        $allocHash = Hash::murmurhash3("{$hashValue}{$flagKey}__{$rule['id']}") % self::MAX_BUCKET;
        $threshold = (float) $trafficAllocation * self::MAX_BUCKET;
        if ($allocHash >= $threshold) {
            return "rule \"{$rule['id']}\" excluded by traffic allocation "
                . "(hash {$allocHash} >= threshold "
                . JsSemantics::numberToString($threshold) . ')';
        }

        return null;
    }
}
