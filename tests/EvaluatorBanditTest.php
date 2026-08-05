<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Tests;

use Avsbhq\Avsb\Core\Evaluator;
use Avsbhq\Avsb\Core\Hash;
use PHPUnit\Framework\TestCase;

/**
 * Bandit dispatch, unknown rule types, and the threshold math that used to
 * diverge between languages.
 *
 * These mirror the bandit and boundary families of the conformance corpus;
 * ConformanceTest runs the recorded cases, and these assert the same behaviour
 * from hand-built datafiles so a failure says which rule is wrong rather than
 * which fixture.
 */
final class EvaluatorBanditTest extends TestCase
{
    private const FLAG_KEY = 'bandit-feature';

    /** @return list<array<string, mixed>> */
    private function variations(): array
    {
        return [
            ['id' => 'var_control', 'key' => 'control', 'value' => 'control-copy'],
            ['id' => 'var_bold', 'key' => 'bold', 'value' => 'bold-copy'],
            ['id' => 'var_plain', 'key' => 'plain', 'value' => 'plain-copy'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $rules
     * @param list<array<string, mixed>>|null $bandits
     * @return array<string, mixed>
     */
    private function datafile(array $rules, ?array $bandits = null, string $key = self::FLAG_KEY): array
    {
        $datafile = [
            'version' => 2,
            'projectType' => 'FEATURE_FLAG',
            'sdkKey' => 'sdk_production_banditfixture01',
            'environmentKey' => 'production',
            'publishedAt' => '2026-05-29T00:00:00.000Z',
            'collectEndpoint' => 'https://ingest.avsb.cloud',
            'flags' => [[
                'id' => 'flag_1',
                'key' => $key,
                'type' => 'string',
                'enabled' => true,
                'defaultVariationId' => 'var_control',
                'variations' => $this->variations(),
                'overrides' => [],
                'rules' => $rules,
            ]],
            'audiences' => [],
        ];
        if ($bandits !== null) {
            $datafile['bandits'] = $bandits;
        }

        return $datafile;
    }

    /**
     * @param list<array<string, mixed>>|null $actions
     * @return array<string, mixed>
     */
    private function banditRule(
        string $id,
        string $algorithm = 'ucb1',
        ?array $actions = null,
        ?string $banditId = null,
        bool $enabled = true
    ): array {
        $rule = [
            'id' => $id,
            'type' => 'bandit',
            'enabled' => $enabled,
            'audienceIds' => [],
            'hashAttribute' => 'user.key',
            'trafficAllocation' => null,
            'variations' => [],
        ];
        if ($banditId !== null) {
            $rule['banditId'] = $banditId;

            return $rule;
        }
        $rule['bandit'] = [
            'algorithm' => $algorithm,
            'rewardMetric' => 'checkout_completed',
            'actions' => $actions ?? [
                ['id' => 'action_a_bold', 'variationId' => 'var_bold'],
                ['id' => 'action_b_plain', 'variationId' => 'var_plain'],
            ],
        ];

        return $rule;
    }

    /** @return array<string, mixed> */
    private function context(): array
    {
        return ['kind' => 'user', 'key' => 'user_bandit'];
    }

    public function testAColdStartBanditDispatchesInsteadOfBeingSkipped(): void
    {
        $reasons = [];
        $result = Evaluator::evaluateFlag(
            self::FLAG_KEY,
            'caller-default',
            $this->context(),
            $this->datafile([$this->banditRule('rule_b')]),
            $reasons
        );

        self::assertSame('bandit', $result['source']);
        self::assertSame('bandit', $result['ruleType']);
        self::assertSame('rule_b', $result['ruleId']);
        // UCB1 samples every unseen action once, lowest id first.
        self::assertSame('bold', $result['variationKey']);
        self::assertSame('bold-copy', $result['value']);
        self::assertSame([
            'rule "rule_b" matched (bandit "rule_b"), served action "action_a_bold" '
            . 'into variation "bold"',
        ], $reasons);
    }

    public function testASharedBanditIdResolvesAndIsAttributedToTheSharedKey(): void
    {
        $bandits = [[
            'id' => 'bandit_checkout',
            'key' => 'checkout-copy-bandit',
            'config' => [
                'algorithm' => 'ucb1',
                'rewardMetric' => 'checkout_completed',
                'actions' => [
                    ['id' => 'action_c_plain', 'variationId' => 'var_plain'],
                    ['id' => 'action_a_bold', 'variationId' => 'var_bold'],
                ],
            ],
        ]];
        $reasons = [];

        $result = Evaluator::evaluateFlag(
            self::FLAG_KEY,
            'caller-default',
            $this->context(),
            $this->datafile(
                [$this->banditRule('rule_shared', banditId: 'bandit_checkout')],
                $bandits
            ),
            $reasons
        );

        self::assertSame('bandit', $result['source']);
        self::assertStringContainsString('matched (bandit "checkout-copy-bandit")', $reasons[0]);
    }

    public function testABanditDecisionRecordsAnExposureNamingTheBanditAndAction(): void
    {
        $captured = [];
        $reasons = [];

        Evaluator::evaluateFlag(
            self::FLAG_KEY,
            'caller-default',
            $this->context(),
            $this->datafile([$this->banditRule('rule_b')]),
            $reasons,
            static function (array $event) use (&$captured): void {
                $captured[] = $event;
            }
        );

        self::assertCount(1, $captured);
        self::assertSame('flag_exposure', $captured[0]['eventName']);
        self::assertSame('rule_b', $captured[0]['experimentId']);
        self::assertSame('var_bold', $captured[0]['variationId']);
        self::assertSame('rule_b', $captured[0]['properties']['banditKey']);
        self::assertSame('action_a_bold', $captured[0]['properties']['actionId']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function unreachableBanditProvider(): array
    {
        return [
            'no config' => [
                ['id' => 'rule_none', 'type' => 'bandit', 'enabled' => true,
                 'audienceIds' => [], 'hashAttribute' => 'user.key',
                 'trafficAllocation' => null, 'variations' => []],
                'rule "rule_none" skipped (bandit rule carries no bandit config)',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $rule
     * @dataProvider unreachableBanditProvider
     */
    public function testABanditRuleThatCannotDispatchNamesWhy(array $rule, string $expected): void
    {
        $reasons = [];
        $result = Evaluator::evaluateFlag(
            self::FLAG_KEY,
            'caller-default',
            $this->context(),
            $this->datafile([$rule]),
            $reasons
        );

        self::assertSame('default', $result['source']);
        self::assertSame($expected, $reasons[0]);
        self::assertSame('no rules matched, returning default variation', $reasons[1]);
    }

    public function testAnUnresolvableBanditIdIsNamed(): void
    {
        $reasons = [];
        Evaluator::evaluateFlag(
            self::FLAG_KEY,
            'caller-default',
            $this->context(),
            $this->datafile([$this->banditRule('rule_ghost', banditId: 'bandit_missing')]),
            $reasons
        );

        self::assertSame(
            'rule "rule_ghost" skipped (banditId "bandit_missing" is not in datafile.bandits)',
            $reasons[0]
        );
    }

    public function testABanditWithNoActionsIsNamed(): void
    {
        $reasons = [];
        Evaluator::evaluateFlag(
            self::FLAG_KEY,
            'caller-default',
            $this->context(),
            $this->datafile([$this->banditRule('rule_empty', actions: [])]),
            $reasons
        );

        self::assertSame(
            'rule "rule_empty" skipped (bandit "rule_empty" declares no actions)',
            $reasons[0]
        );
    }

    public function testAnActionPointingAtADeletedVariationIsNamed(): void
    {
        $reasons = [];
        Evaluator::evaluateFlag(
            self::FLAG_KEY,
            'caller-default',
            $this->context(),
            $this->datafile([$this->banditRule(
                'rule_gv',
                actions: [['id' => 'action_ghost', 'variationId' => 'var_deleted']]
            )]),
            $reasons
        );

        self::assertSame(
            'rule "rule_gv" skipped (bandit "rule_gv" action "action_ghost" targets '
            . 'unknown variation "var_deleted")',
            $reasons[0]
        );
    }

    public function testADisabledBanditRuleIsSkippedBeforeTheEngine(): void
    {
        $reasons = [];
        Evaluator::evaluateFlag(
            self::FLAG_KEY,
            'caller-default',
            $this->context(),
            $this->datafile([$this->banditRule('rule_off', enabled: false)]),
            $reasons
        );

        self::assertSame('rule "rule_off" skipped (disabled)', $reasons[0]);
    }

    public function testAnUnknownRuleTypeIsNamedAndALaterRuleServes(): void
    {
        $future = ['id' => 'rule_future', 'type' => 'multi_armed_rollout', 'enabled' => true,
                   'audienceIds' => [], 'hashAttribute' => 'user.key',
                   'trafficAllocation' => null,
                   'variations' => [['variationId' => 'var_bold', 'percentage' => 1]]];
        $known = ['id' => 'rule_known', 'type' => 'targeted_delivery', 'enabled' => true,
                  'audienceIds' => [], 'hashAttribute' => 'user.key',
                  'trafficAllocation' => null,
                  'variations' => [['variationId' => 'var_plain', 'percentage' => 1]]];
        $reasons = [];

        $result = Evaluator::evaluateFlag(
            self::FLAG_KEY,
            'caller-default',
            $this->context(),
            $this->datafile([$future, $known]),
            $reasons
        );

        self::assertSame('rule_known', $result['ruleId']);
        self::assertSame(
            'rule "rule_future" skipped (unsupported rule type "multi_armed_rollout")',
            $reasons[0]
        );
    }

    // ------------------------------------------------------------------
    // Threshold and cumulative math
    // ------------------------------------------------------------------

    /**
     * @param list<array<string, mixed>> $percentages
     * @return array<string, mixed>
     */
    private function abDatafile(?float $traffic, array $percentages): array
    {
        return $this->datafile([[
            'id' => 'rule_alloc',
            'type' => 'ab_test',
            'enabled' => true,
            'audienceIds' => [],
            'hashAttribute' => 'user.key',
            'trafficAllocation' => $traffic,
            'variations' => $percentages,
        ]], null, 'alloc-feature');
    }

    private function allocationBucket(): int
    {
        return Hash::murmurhash3('user_banditalloc-feature__rule_alloc') % 10000;
    }

    private function assignmentBucket(): int
    {
        return Hash::murmurhash3('user_banditalloc-feature') % 10000;
    }

    /**
     * The case that catches truncated threshold math. The threshold lands on
     * bucket + 0.5, so a float comparison keeps the context inside the rollout
     * while flooring the threshold to bucket excludes it.
     */
    public function testABucketHalfAStepBelowAFractionalThresholdIsIncluded(): void
    {
        $traffic = ($this->allocationBucket() + 0.5) / 10000;
        $reasons = [];

        $result = Evaluator::evaluateFlag(
            'alloc-feature',
            'caller-default',
            $this->context(),
            $this->abDatafile($traffic, [['variationId' => 'var_bold', 'percentage' => 1]]),
            $reasons
        );

        self::assertSame('rule', $result['source']);
        self::assertSame('bold', $result['variationKey']);
    }

    /** Proves the case above is not vacuous: the rule really can be skipped. */
    public function testABucketHalfAStepAboveTheSameThresholdIsExcluded(): void
    {
        $traffic = ($this->allocationBucket() - 0.5) / 10000;
        $reasons = [];

        $result = Evaluator::evaluateFlag(
            'alloc-feature',
            'caller-default',
            $this->context(),
            $this->abDatafile($traffic, [['variationId' => 'var_bold', 'percentage' => 1]]),
            $reasons
        );

        self::assertSame('default', $result['source']);
        self::assertStringContainsString(
            'excluded by traffic allocation (hash ' . $this->allocationBucket() . ' >=',
            $reasons[0]
        );
    }

    public function testAnIntegralThresholdPrintsWithoutADecimalPoint(): void
    {
        // 0 percent excludes every bucket, so this is deterministic, and the
        // threshold must print as "0" rather than PHP's "0.0" or "0.0E+0".
        // Every reason string in the corpus is compared exactly.
        $reasons = [];
        Evaluator::evaluateFlag(
            'alloc-feature',
            'caller-default',
            $this->context(),
            $this->abDatafile(0.0, [['variationId' => 'var_bold', 'percentage' => 1]]),
            $reasons
        );

        self::assertStringEndsWith('threshold 0)', $reasons[0]);
    }

    public function testACumulativeVariationBoundaryStaysInFloatingPoint(): void
    {
        // The first variation's boundary lands on the assignment bucket plus a
        // half step, so the bucket belongs to it under float math and to the
        // next variation under a truncated one.
        $first = ($this->assignmentBucket() + 0.5) / 10000;
        $reasons = [];

        $result = Evaluator::evaluateFlag(
            'alloc-feature',
            'caller-default',
            $this->context(),
            $this->abDatafile(1.0, [
                ['variationId' => 'var_bold', 'percentage' => $first],
                ['variationId' => 'var_plain', 'percentage' => 1 - $first],
            ]),
            $reasons
        );

        self::assertSame('bold', $result['variationKey']);
        // The printed boundary carries its fraction rather than being rounded.
        self::assertMatchesRegularExpression(
            '#\(hash ' . $this->assignmentBucket() . '/\d+\.\d+\)$#',
            $reasons[0]
        );
    }
}
