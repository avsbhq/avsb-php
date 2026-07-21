<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Core;

/**
 * Holdout evaluator: PHP port of holdouts.ts from @avsbhq/core.
 *
 * Spec §8.2: holdouts + mutual exclusion.
 *
 * Walks the datafile's active holdouts; the first one that participates
 * in $flagId and whose bucket lands inside its traffic-allocation window
 * classifies the context as held out for that flag. Bucketing is
 * murmurhash3(holdoutKey + ':' + bucketingKey) % 10_000 so multiple
 * flags sharing a holdout get a consistent classification per context.
 */
final class Holdouts
{
    /**
     * @param array $context  EvalContext array (single or multi).
     * @param string $flagId  The datafile flag id to check.
     * @param array $datafile Decoded FlagDatafile array.
     *
     * @return array{heldOut: bool, holdout?: array, variationId?: string}
     */
    public static function evaluateHoldout(
        array $context,
        string $flagId,
        array $datafile
    ): array {
        $holdouts = $datafile['holdouts'] ?? [];
        if (empty($holdouts)) {
            return ['heldOut' => false];
        }

        foreach ($holdouts as $holdout) {
            $flagIds = $holdout['flagIds'] ?? [];
            if (!in_array($flagId, $flagIds, true)) {
                continue;
            }

            $trafficAllocation = (float) ($holdout['trafficAllocation'] ?? 0);
            if ($trafficAllocation <= 0) {
                continue;
            }

            $hashAttr  = $holdout['hashAttribute'] ?? 'userId';
            $bucketKey = self::holdoutBucketKey($context, $hashAttr);
            $hash      = Hash::murmurhash3($holdout['key'] . ':' . $bucketKey) % 10000;
            $threshold = (int) floor($trafficAllocation * 10000);

            if ($hash >= $threshold) {
                continue;
            }

            $variationId = $holdout['defaultVariationByFlag'][$flagId] ?? null;
            $result = ['heldOut' => true, 'holdout' => $holdout];
            if ($variationId !== null) {
                $result['variationId'] = $variationId;
            }
            return $result;
        }

        return ['heldOut' => false];
    }

    /**
     * Resolve the bucketing key for a holdout's hashAttribute against an
     * EvalContext. Returns '' when the requested kind/attribute combo is absent.
     */
    private static function holdoutBucketKey(array $context, string $hashAttribute): string
    {
        $value = Context::resolveDottedAttribute($context, $hashAttribute);
        if ($value === null) {
            return '';
        }
        return (string) $value;
    }
}
