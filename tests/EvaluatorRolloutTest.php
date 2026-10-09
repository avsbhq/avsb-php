<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Tests;

use Avsbhq\Avsb\Core\Evaluator;
use Avsbhq\Avsb\Core\Hash;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Targeted delivery rollouts (N1-1, production QA 2026-10-01).
 *
 * A TARGETED_DELIVERY rule with trafficAllocation 0.3 used to serve 100
 * percent of its audience in every SDK, because the evaluator only applied the
 * allocation to ab_test rules. These tests lock the fix: the same gate, hash
 * input and float threshold as an ab_test rule. They mirror
 * packages/avsb-core/src/evaluate.rollout.test.ts, and the exact counts were
 * computed by the TypeScript reference (@avsbhq/core 1.6.0) over the same keys.
 */
final class EvaluatorRolloutTest extends TestCase
{
    private const FLAG_KEY = 'rollout_feature';
    private const RULE_ID = 'rule_rollout';
    private const USERS = 10000;

    /**
     * @param list<string> $audienceIds
     * @return array<string, mixed>
     */
    private static function rule(
        ?float $allocation,
        string $id = self::RULE_ID,
        string $variationId = 'var_on',
        string $type = 'targeted_delivery',
        array $audienceIds = [],
        bool $withVariations = true
    ): array {
        return [
            'id' => $id,
            'type' => $type,
            'enabled' => true,
            'audienceIds' => $audienceIds,
            'hashAttribute' => 'user.key',
            'trafficAllocation' => $allocation,
            'variations' => $withVariations ? [['variationId' => $variationId, 'percentage' => 1]] : [],
        ];
    }

    /**
     * @param list<array<string, mixed>> $rules
     * @param list<array<string, mixed>> $audiences
     * @return array<string, mixed>
     */
    private static function datafile(array $rules, array $audiences = []): array
    {
        return [
            'version' => 2,
            'projectType' => 'FEATURE_FLAG',
            'sdkKey' => 'sdk_test_rolloutfixture00001',
            'environmentKey' => 'test',
            'publishedAt' => '2026-10-01T00:00:00.000Z',
            'collectEndpoint' => 'https://collect.test.invalid',
            'flags' => [[
                'id' => 'flag_rollout',
                'key' => self::FLAG_KEY,
                'type' => 'boolean',
                'enabled' => true,
                'defaultVariationId' => 'var_off',
                'variations' => [
                    ['id' => 'var_off', 'key' => 'off', 'value' => false],
                    ['id' => 'var_on', 'key' => 'on', 'value' => true],
                    ['id' => 'var_fallback', 'key' => 'fallback', 'value' => true],
                ],
                'overrides' => [],
                'rules' => $rules,
            ]],
            'audiences' => $audiences,
        ];
    }

    /**
     * @param array<string, mixed> $datafile
     * @param array<string, mixed> $attributes
     * @param list<string> $reasons
     * @return array<string, mixed>
     */
    private static function evaluate(array $datafile, string $key, array $attributes = [], array &$reasons = []): array
    {
        return Evaluator::evaluateFlag(
            self::FLAG_KEY,
            false,
            array_merge(['kind' => 'user', 'key' => $key], $attributes),
            $datafile,
            $reasons
        );
    }

    /** @param array<string, mixed> $datafile */
    private static function servedCount(array $datafile, int $count = self::USERS): int
    {
        $served = 0;
        for ($i = 0; $i < $count; $i++) {
            if (self::evaluate($datafile, "user-{$i}")['ruleId'] === self::RULE_ID) {
                $served++;
            }
        }

        return $served;
    }

    private static function allocationBucket(string $key): int
    {
        return Hash::murmurhash3($key . self::FLAG_KEY . '__' . self::RULE_ID) % 10000;
    }

    private static function keyForAllocationBucket(int $bucket, string $prefix): string
    {
        for ($i = 0; $i < 200000; $i++) {
            $candidate = "{$prefix}-{$i}";
            if (self::allocationBucket($candidate) === $bucket) {
                return $candidate;
            }
        }

        throw new RuntimeException("no key hashed to allocation bucket {$bucket}");
    }

    // ------------------------------------------------------------------
    // Rollout share
    // ------------------------------------------------------------------

    public function testAThirtyPercentRuleServesTheReferenceShareOf10000Users(): void
    {
        // The TypeScript reference serves exactly 2,984 of these keys.
        self::assertSame(2984, self::servedCount(self::datafile([self::rule(0.3)])));
    }

    public function testTheFiveAndFiftyPercentRolloutsMatchTheReferenceCounts(): void
    {
        self::assertSame(536, self::servedCount(self::datafile([self::rule(0.05)])));
        self::assertSame(4939, self::servedCount(self::datafile([self::rule(0.5)])));
    }

    public function testANullAllocationServesEveryoneExactlyAsBeforeTheFix(): void
    {
        self::assertSame(2000, self::servedCount(self::datafile([self::rule(null)]), 2000));
    }

    public function testAFullAllocationServesEveryoneAndAZeroAllocationNoOne(): void
    {
        self::assertSame(2000, self::servedCount(self::datafile([self::rule(1.0)]), 2000));
        self::assertSame(0, self::servedCount(self::datafile([self::rule(0.0)]), 2000));
    }

    public function testGrowingARolloutKeepsEveryoneAlreadyInsideIt(): void
    {
        $small = self::datafile([self::rule(0.05)]);
        $large = self::datafile([self::rule(0.3)]);
        for ($i = 0; $i < 2000; $i++) {
            if (self::evaluate($small, "user-{$i}")['ruleId'] !== self::RULE_ID) {
                continue;
            }
            self::assertSame(self::RULE_ID, self::evaluate($large, "user-{$i}")['ruleId'], "user-{$i}");
        }
    }

    // ------------------------------------------------------------------
    // Rollout membership
    // ------------------------------------------------------------------

    public function testTheAbTestHashInputDecidesMembershipForBothRuleTypes(): void
    {
        $asTargeted = self::datafile([self::rule(0.3)]);
        $asAbTest = self::datafile([self::rule(0.3, type: 'ab_test')]);
        for ($i = 0; $i < 2000; $i++) {
            $inRollout = self::allocationBucket("user-{$i}") < 3000;
            self::assertSame($inRollout, self::evaluate($asTargeted, "user-{$i}")['ruleId'] === self::RULE_ID);
            self::assertSame($inRollout, self::evaluate($asAbTest, "user-{$i}")['ruleId'] === self::RULE_ID);
        }
    }

    public function testTheFirstUsersBucketExactlyAsTheReferenceDoes(): void
    {
        self::assertSame(
            [5278, 8498, 4278, 4172, 1088],
            array_map(static fn (int $i): int => self::allocationBucket("user-{$i}"), range(0, 4))
        );
    }

    public function testTheThresholdIsTheRawFloatSoBucket1234IsInsideA012345Rollout(): void
    {
        $datafile = self::datafile([self::rule(0.12345)]);
        $inside = self::keyForAllocationBucket(1234, 'td-in');
        $outside = self::keyForAllocationBucket(1235, 'td-out');
        // The same keys the TypeScript search settles on.
        self::assertSame(['td-in-3679', 'td-out-19194'], [$inside, $outside]);

        self::assertSame(self::RULE_ID, self::evaluate($datafile, $inside)['ruleId']);

        $reasons = [];
        $result = self::evaluate($datafile, $outside, [], $reasons);
        self::assertSame('default', $result['source']);
        self::assertSame(
            [
                'rule "rule_rollout" excluded by traffic allocation (hash 1235 >= threshold 1234.5)',
                'no rules matched, returning default variation',
            ],
            $reasons
        );
    }

    public function testAVisitorOutsideTheRolloutFallsThroughToTheNextRule(): void
    {
        $datafile = self::datafile([
            self::rule(0.3),
            self::rule(null, id: 'rule_everyone', variationId: 'var_fallback'),
        ]);
        $result = self::evaluate($datafile, self::keyForAllocationBucket(9000, 'td-next'));

        self::assertSame('rule_everyone', $result['ruleId']);
        self::assertSame('fallback', $result['variationKey']);
    }

    public function testAnAudienceMissStillSkipsBeforeTheRolloutIsConsulted(): void
    {
        $paid = [
            'id' => 'aud_paid',
            'rules' => [
                'id' => 'g_root',
                'logic' => 'AND',
                'conditions' => [[
                    'id' => 'c_plan',
                    'type' => 'CUSTOM_ATTRIBUTE',
                    'attribute' => 'plan',
                    'operator' => 'equals',
                    'value' => 'paid',
                    'contextKind' => 'user',
                ]],
            ],
        ];
        $datafile = self::datafile([self::rule(1.0, audienceIds: ['aud_paid'])], [$paid]);

        $reasons = [];
        $result = self::evaluate($datafile, 'free-user', ['plan' => 'free'], $reasons);
        self::assertSame('default', $result['source']);
        self::assertSame('rule "rule_rollout" skipped (audience not matched)', $reasons[0]);
    }

    public function testARuleWithNoVariationsStaysASilentSkipWhateverItsAllocation(): void
    {
        $reasons = [];
        self::evaluate(self::datafile([self::rule(0.0, withVariations: false)]), 'u1', [], $reasons);
        self::assertSame(['no rules matched, returning default variation'], $reasons);
    }

    public function testATargetedDeliveryDecisionFiresNoExposureRolloutOrNot(): void
    {
        $exposures = [];
        $reasons = [];
        $result = Evaluator::evaluateFlag(
            self::FLAG_KEY,
            false,
            ['kind' => 'user', 'key' => self::keyForAllocationBucket(10, 'td-expo')],
            self::datafile([self::rule(0.3)]),
            $reasons,
            static function (array $event) use (&$exposures): void {
                $exposures[] = $event;
            }
        );

        self::assertSame('targeted_delivery', $result['ruleType']);
        self::assertSame([], $exposures);
    }
}
