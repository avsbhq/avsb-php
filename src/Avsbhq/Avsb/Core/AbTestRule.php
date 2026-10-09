<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Core;

/**
 * The ab_test branch of the evaluator: allocation gate (AllocationGate, shared
 * with targeted_delivery rules), then bucket walk.
 *
 * Both comparisons are FLOAT comparisons against the raw product, never a
 * truncated or rounded integer. A 0.12345 rollout puts the threshold at 1234.5:
 * bucket 1234 is inside it, while a port that truncates to 1234 excludes that
 * context and diverges from every other SDK by one bucket in ten thousand.
 * Cross-language assignment skew is the one bug a customer can never diagnose,
 * so the boundary fixtures lock this at fractional and at integer thresholds.
 *
 * @internal
 */
final class AbTestRule
{
    private const MAX_BUCKET = 10000;

    /**
     * @param array<string, mixed> $flag
     * @param array<string, mixed> $rule
     * @param list<string> $reasons appended to in place
     * @param (callable(array<string, mixed>): void)|null $onExposure
     * @return array<string, mixed>|null null to continue to the next rule
     */
    public static function evaluate(
        array $flag,
        array $rule,
        string $flagKey,
        mixed $defaultValue,
        string $hashValue,
        array &$reasons,
        ?callable $onExposure
    ): ?array {
        // The allocation gate is shared with targeted_delivery rules (N1-1).
        $exclusion = AllocationGate::exclusion($rule, $flagKey, $hashValue);
        if ($exclusion !== null) {
            $reasons[] = $exclusion;

            return null;
        }

        $hash = Hash::murmurhash3("{$hashValue}{$flagKey}") % self::MAX_BUCKET;
        $cumulative = 0.0;

        foreach ($rule['variations'] ?? [] as $rv) {
            // Cumulative boundaries stay in floating point for the same reason
            // the threshold does: 0.12345 accumulates to 1234.5, and bucket
            // 1234 belongs to the first variation.
            $cumulative += (float) ($rv['percentage'] ?? 0) * self::MAX_BUCKET;
            if ($hash >= $cumulative) {
                continue;
            }

            $expVar = EvaluationResult::variation($flag, $rv['variationId'] ?? '');

            if ($onExposure !== null && $expVar !== null) {
                $onExposure([
                    'visitorId' => $hashValue,
                    'experimentId' => $rule['id'],
                    'variationId' => $rv['variationId'] ?? '',
                    'eventName' => 'flag_exposure',
                    'revenue' => null,
                    'timestamp' => EvaluationResult::nowMs(),
                    'pageUrl' => '',
                    'properties' => [
                        'flagKey' => $flagKey,
                        'flagId' => $flag['id'] ?? '',
                    ],
                ]);
            }

            $reasons[] = "rule \"{$rule['id']}\" matched (ab_test), bucketed into variation \""
                . EvaluationResult::keyText($expVar) . '" (hash ' . $hash . '/'
                . JsSemantics::numberToString($cumulative) . ')';

            return EvaluationResult::make(
                $expVar !== null ? $expVar['value'] : $defaultValue,
                $expVar !== null ? $expVar['key'] : null,
                'rule',
                $rule['id'],
                'ab_test',
                $flagKey,
                $reasons
            );
        }

        return null;
    }
}
