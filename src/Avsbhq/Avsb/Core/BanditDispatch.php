<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Core;

/**
 * The bandit branch of the evaluator.
 *
 * Every exit appends a reason. A bandit-typed rule used to match neither the
 * targeted_delivery nor the ab_test branch and fall through with nothing
 * recorded, so a configured bandit behaved as "no rules matched" with no log
 * anywhere: the silent drop the spec bans.
 *
 * @internal
 */
final class BanditDispatch
{
    /**
     * @param array<string, mixed> $flag
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $context
     * @param array<string, mixed> $datafile
     * @param list<string> $reasons appended to in place
     * @param (callable(array<string, mixed>): void)|null $onExposure
     * @return array<string, mixed>|null null to continue to the next rule
     */
    public static function evaluate(
        array $flag,
        array $rule,
        string $flagKey,
        string $hashValue,
        array $context,
        array $datafile,
        array &$reasons,
        ?callable $onExposure,
        ?BanditModelStore $store
    ): ?array {
        $bandit = self::resolveRuleBandit($rule, $datafile);
        if ($bandit === null) {
            $reasons[] = isset($rule['banditId'])
                ? "rule \"{$rule['id']}\" skipped (banditId \"{$rule['banditId']}\" "
                    . 'is not in datafile.bandits)'
                : "rule \"{$rule['id']}\" skipped (bandit rule carries no bandit config)";

            return null;
        }

        if (empty($bandit['config']['actions'])) {
            $reasons[] = "rule \"{$rule['id']}\" skipped "
                . "(bandit \"{$bandit['key']}\" declares no actions)";

            return null;
        }

        // Models are learned client-layer state, not published in the datafile,
        // so with no store the algorithm runs its documented cold-start policy
        // rather than the rule disappearing.
        $modelStore = $store ?? new ColdStartBanditModelStore(
            (string) ($bandit['config']['algorithm'] ?? 'epsilon-greedy')
        );
        $decision = Bandits::evaluateBandit($bandit['key'], $bandit['config'], $context, $modelStore);
        if ($decision === null) {
            $reasons[] = "rule \"{$rule['id']}\" skipped "
                . "(bandit \"{$bandit['key']}\" has no model in the supplied store)";

            return null;
        }

        $action = $decision['action'];
        $banditVar = EvaluationResult::variation($flag, $action['variationId'] ?? '');
        if ($banditVar === null) {
            $reasons[] = "rule \"{$rule['id']}\" skipped (bandit \"{$bandit['key']}\" action "
                . "\"{$action['id']}\" targets unknown variation \"{$action['variationId']}\")";

            return null;
        }

        if ($onExposure !== null) {
            $onExposure([
                'visitorId' => $hashValue,
                'experimentId' => $rule['id'],
                'variationId' => $action['variationId'],
                'eventName' => 'flag_exposure',
                'revenue' => null,
                'timestamp' => EvaluationResult::nowMs(),
                'pageUrl' => '',
                'properties' => [
                    'flagKey' => $flagKey,
                    'flagId' => $flag['id'] ?? '',
                    'banditKey' => $bandit['key'],
                    'actionId' => $action['id'],
                ],
            ]);
        }

        $reasons[] = "rule \"{$rule['id']}\" matched (bandit \"{$bandit['key']}\"), "
            . "served action \"{$action['id']}\" into variation \"{$banditVar['key']}\"";

        return EvaluationResult::make(
            $banditVar['value'],
            $banditVar['key'],
            'bandit',
            $rule['id'],
            'bandit',
            $flagKey,
            $reasons
        );
    }

    /**
     * Resolve the bandit a bandit-typed rule points at.
     *
     * A shared banditId (joined against datafile.bandits) wins over an inline
     * rule.bandit, and the resolved key is what addresses the model store: the
     * shared entry's key, or the rule's own id for an inline config.
     *
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $datafile
     * @return array{key: string, config: array<string, mixed>}|null
     */
    private static function resolveRuleBandit(array $rule, array $datafile): ?array
    {
        $banditId = $rule['banditId'] ?? null;
        if (is_string($banditId) && $banditId !== '') {
            foreach ($datafile['bandits'] ?? [] as $shared) {
                if (($shared['id'] ?? null) === $banditId) {
                    return [
                        'key' => (string) ($shared['key'] ?? $banditId),
                        'config' => $shared['config'] ?? [],
                    ];
                }
            }

            return null;
        }

        $inline = $rule['bandit'] ?? null;
        if (is_array($inline)) {
            return ['key' => (string) $rule['id'], 'config' => $inline];
        }

        return null;
    }
}
