<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Core;

/**
 * Flag evaluator: PHP port of evaluate.ts from @avsbhq/core.
 *
 * Evaluation order, mirroring the reference step for step and locked by the
 * conformance corpus including the exact reason strings in their exact order:
 *
 *   1. Flag not found  -> not_found
 *   2. Flag disabled   -> disabled (serves the default variation's value)
 *   3. User override   -> datafileOverride
 *   4. Holdout         -> holdout
 *   5. Rules, in order -> rule (targeted_delivery, ab_test) or bandit
 *   6. Default         -> default
 *
 * Every skipped rule appends a reason. A rule that disappears from the
 * decision trail is the silent drop the spec bans.
 */
final class Evaluator
{
    /**
     * Evaluate a flag key against the datafile and context.
     *
     * @param string $flagKey
     * @param mixed $defaultValue            caller-supplied fallback
     * @param array<string, mixed> $context  EvalContext array, single or multi
     * @param array<string, mixed> $datafile decoded FlagDatafile array
     * @param list<string> $reasons          mutable accumulator, appended in place
     * @param callable(array<string, mixed>): void|null $onExposure receives an
     *        exposure payload when an ab_test, bandit, or holdout decision is served
     * @param BanditModelStore|null $banditModelStore when null, bandit rules run
     *        their cold-start policy instead of being skipped
     *
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
    public static function evaluateFlag(
        string $flagKey,
        mixed $defaultValue,
        array $context,
        array $datafile,
        array &$reasons = [],
        ?callable $onExposure = null,
        ?BanditModelStore $banditModelStore = null
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

        // 3. CHECK OVERRIDES, matched on the primary context's key.
        $primaryKey = Context::bucketingKey($context, 'user');
        if ($primaryKey === '') {
            $primary = Context::primaryContext($context);
            $primaryKey = (string) ($primary['key'] ?? '');
        }

        foreach ($flag['overrides'] ?? [] as $override) {
            if (($override['userId'] ?? null) === $primaryKey) {
                $reasons[] = "matched user override for userId=\"{$primaryKey}\"";
                $overrideVar = self::getVariationValue($flag, $override['variationId'] ?? '');

                return self::result(
                    $overrideVar !== null ? $overrideVar['value'] : $defaultValue,
                    $overrideVar !== null ? $overrideVar['key'] : null,
                    // The public spelling. `override` is an internal evaluator
                    // name in other ports and must never reach a caller.
                    'datafileOverride',
                    null,
                    null,
                    $flagKey,
                    $reasons
                );
            }
        }

        // 4. HOLDOUT CHECK, between overrides and the rules loop so explicit
        //    operator intent still wins while every rule honours the holdout.
        $holdoutResult = Holdouts::evaluateHoldout($context, $flag['id'] ?? '', $datafile);
        if ($holdoutResult['heldOut'] && isset($holdoutResult['holdout'])) {
            return self::serveHoldout(
                $flag,
                $flagKey,
                $defaultValue,
                $holdoutResult,
                $primaryKey,
                $reasons,
                $onExposure
            );
        }

        // Audience lookup, built once per evaluation.
        $audienceMap = [];
        foreach ($datafile['audiences'] ?? [] as $audience) {
            $audienceMap[$audience['id']] = $audience;
        }
        $currencyExponent = isset($datafile['currencyExponent'])
            && is_int($datafile['currencyExponent'])
                ? $datafile['currencyExponent']
                : null;

        // 5. EVALUATE RULES (first match wins)
        foreach ($flag['rules'] ?? [] as $rule) {
            if (!($rule['enabled'] ?? true)) {
                $reasons[] = "rule \"{$rule['id']}\" skipped (disabled)";
                continue;
            }

            $hashPath = $rule['hashAttribute'] ?? 'userId';
            $resolved = Context::resolveDottedAttribute($context, $hashPath);
            $hashValue = $resolved === null ? '' : self::stringify($resolved);

            // a. AUDIENCE CHECK
            $audienceIds = $rule['audienceIds'] ?? [];
            if (!empty($audienceIds)) {
                $matched = false;
                foreach ($audienceIds as $audienceId) {
                    $audience = $audienceMap[$audienceId] ?? null;
                    if ($audience !== null
                        && Audience::evaluateAudience($audience, $context, $currencyExponent)
                    ) {
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
                    $targetVar = self::getVariationValue(
                        $flag,
                        $rule['variations'][0]['variationId'] ?? ''
                    );
                    $reasons[] = "rule \"{$rule['id']}\" matched (targeted_delivery), "
                        . 'serving variation "' . self::keyText($targetVar) . '"';

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
                    $abResult = AbTestRule::evaluate(
                        $flag,
                        $rule,
                        $flagKey,
                        $defaultValue,
                        $hashValue,
                        $reasons,
                        $onExposure
                    );
                    if ($abResult === null) {
                        continue 2;
                    }

                    return $abResult;

                case 'bandit':
                    $banditResult = BanditDispatch::evaluate(
                        $flag,
                        $rule,
                        $flagKey,
                        $hashValue,
                        $context,
                        $datafile,
                        $reasons,
                        $onExposure,
                        $banditModelStore
                    );
                    if ($banditResult === null) {
                        continue 2;
                    }

                    return $banditResult;

                default:
                    // Unknown rule type on the wire: name it rather than
                    // dropping it.
                    $type = is_string($rule['type'] ?? null) ? $rule['type'] : '';
                    $reasons[] = "rule \"{$rule['id']}\" skipped "
                        . "(unsupported rule type \"{$type}\")";
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
    // Rule branches
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $flag
     * @param array<string, mixed> $holdoutResult
     * @param list<string> $reasons
     * @return array<string, mixed>
     */
    private static function serveHoldout(
        array $flag,
        string $flagKey,
        mixed $defaultValue,
        array $holdoutResult,
        string $primaryKey,
        array &$reasons,
        ?callable $onExposure
    ): array {
        $holdout = $holdoutResult['holdout'];
        $targetVarId = $holdoutResult['variationId'] ?? $flag['defaultVariationId'] ?? '';
        $holdoutVar = self::getVariationValue($flag, $targetVarId);

        if ($onExposure !== null) {
            $onExposure([
                'visitorId' => $primaryKey,
                'experimentId' => $holdout['id'],
                'variationId' => $targetVarId,
                'eventName' => 'flag_holdout_exposure',
                'revenue' => null,
                'timestamp' => self::nowMs(),
                'pageUrl' => '',
                'properties' => [
                    'flagKey' => $flagKey,
                    'flagId' => $flag['id'] ?? '',
                    'holdoutId' => $holdout['id'],
                    'holdoutKey' => $holdout['key'],
                ],
            ]);
        }

        $servedKey = $holdoutVar !== null ? $holdoutVar['key'] : $targetVarId;
        $reasons[] = "held out by holdout \"{$holdout['key']}\", "
            . "serving default variation \"{$servedKey}\"";

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

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @param array<int, array<string, mixed>> $flags */
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
     * @param array<string, mixed> $flag
     * @return array{value: mixed, key: string}|null
     */
    private static function getVariationValue(array $flag, string $variationId): ?array
    {
        return EvaluationResult::variation($flag, $variationId);
    }

    /** @param array{value: mixed, key: string}|null $variation */
    private static function keyText(?array $variation): string
    {
        return EvaluationResult::keyText($variation);
    }

    private static function stringify(mixed $value): string
    {
        return EvaluationResult::stringify($value);
    }

    private static function nowMs(): float
    {
        return EvaluationResult::nowMs();
    }

    /**
     * @param list<string> $reasons
     * @return array<string, mixed>
     */
    private static function result(
        mixed $value,
        ?string $variationKey,
        string $source,
        ?string $ruleId,
        ?string $ruleType,
        string $flagKey,
        array $reasons
    ): array {
        return EvaluationResult::make(
            $value,
            $variationKey,
            $source,
            $ruleId,
            $ruleType,
            $flagKey,
            $reasons
        );
    }
}
