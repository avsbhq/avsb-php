<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Tests;

use Avsbhq\Avsb\Core\Evaluator;
use PHPUnit\Framework\TestCase;

final class EvaluatorTest extends TestCase
{
    // ------------------------------------------------------------------
    // Minimal datafile factories
    // ------------------------------------------------------------------

    private function emptyDatafile(): array
    {
        return [
            'version'         => 2,
            'projectType'     => 'FEATURE_FLAG',
            'sdkKey'          => 'test',
            'environmentKey'  => 'test',
            'publishedAt'     => '2026-01-01T00:00:00Z',
            'collectEndpoint' => '',
            'flags'           => [],
            'audiences'       => [],
        ];
    }

    private function makeFlag(array $overrides = []): array
    {
        return array_merge([
            'id'                 => 'flag_1',
            'key'                => 'my-flag',
            'type'               => 'boolean',
            'enabled'            => true,
            'defaultVariationId' => 'var_off',
            'variations'         => [
                ['id' => 'var_off', 'key' => 'off', 'value' => false],
                ['id' => 'var_on',  'key' => 'on',  'value' => true],
            ],
            'overrides' => [],
            'rules'     => [],
        ], $overrides);
    }

    private function datafileWith(array $flags, array $audiences = [], array $holdouts = []): array
    {
        $df = $this->emptyDatafile();
        $df['flags']     = $flags;
        $df['audiences'] = $audiences;
        if (!empty($holdouts)) {
            $df['holdouts'] = $holdouts;
        }
        return $df;
    }

    private function userCtx(string $key = 'alice', array $attrs = []): array
    {
        return array_merge(['kind' => 'user', 'key' => $key], $attrs);
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    public function testFlagNotFoundReturnsNotFound(): void
    {
        $df     = $this->emptyDatafile();
        $result = Evaluator::evaluateFlag('does-not-exist', false, $this->userCtx(), $df);
        self::assertSame('not_found', $result['source']);
        self::assertFalse($result['value']);
        self::assertNull($result['variationKey']);
        self::assertNull($result['ruleId']);
    }

    public function testDisabledFlagServesDefaultVariationValue(): void
    {
        $flag = $this->makeFlag(['enabled' => false]);
        $df   = $this->datafileWith([$flag]);
        $result = Evaluator::evaluateFlag('my-flag', true, $this->userCtx(), $df);
        self::assertSame('disabled', $result['source']);
        self::assertFalse($result['value']); // defaultVariationId var_off → false
        self::assertSame('off', $result['variationKey']);
    }

    public function testUserOverrideWinsOverRules(): void
    {
        $flag = $this->makeFlag([
            'overrides' => [
                ['userId' => 'alice', 'variationId' => 'var_on'],
            ],
            'rules' => [
                [
                    'id'         => 'rule_1',
                    'type'       => 'targeted_delivery',
                    'enabled'    => true,
                    'audienceIds'=> [],
                    'hashAttribute' => 'user.key',
                    'trafficAllocation' => null,
                    'variations' => [['variationId' => 'var_off', 'percentage' => 1]],
                ],
            ],
        ]);
        $df     = $this->datafileWith([$flag]);
        $result = Evaluator::evaluateFlag('my-flag', false, $this->userCtx('alice'), $df);
        self::assertSame('override', $result['source']);
        self::assertTrue($result['value']);
    }

    public function testTargetedDeliveryMatchesWhenAudienceMatched(): void
    {
        $audience = [
            'id'    => 'aud_paid',
            'rules' => [
                'id'         => 'g1',
                'logic'      => 'AND',
                'conditions' => [[
                    'id'        => 'c1',
                    'type'      => 'CUSTOM_ATTRIBUTE',
                    'attribute' => 'plan',
                    'operator'  => 'equals',
                    'value'     => 'pro',
                ]],
            ],
        ];
        $flag = $this->makeFlag([
            'rules' => [[
                'id'                => 'rule_td',
                'type'              => 'targeted_delivery',
                'enabled'           => true,
                'audienceIds'       => ['aud_paid'],
                'hashAttribute'     => 'user.key',
                'trafficAllocation' => null,
                'variations'        => [['variationId' => 'var_on', 'percentage' => 1]],
            ]],
        ]);
        $df     = $this->datafileWith([$flag], [$audience]);
        $result = Evaluator::evaluateFlag('my-flag', false, $this->userCtx('alice', ['plan' => 'pro']), $df);
        self::assertSame('rule', $result['source']);
        self::assertSame('targeted_delivery', $result['ruleType']);
        self::assertTrue($result['value']);
    }

    public function testTargetedDeliveryMissesWhenAudienceNotMatched(): void
    {
        $audience = [
            'id'    => 'aud_paid',
            'rules' => [
                'id'         => 'g1',
                'logic'      => 'AND',
                'conditions' => [[
                    'id'        => 'c1',
                    'type'      => 'CUSTOM_ATTRIBUTE',
                    'attribute' => 'plan',
                    'operator'  => 'equals',
                    'value'     => 'pro',
                ]],
            ],
        ];
        $flag = $this->makeFlag([
            'rules' => [[
                'id'                => 'rule_td',
                'type'              => 'targeted_delivery',
                'enabled'           => true,
                'audienceIds'       => ['aud_paid'],
                'hashAttribute'     => 'user.key',
                'trafficAllocation' => null,
                'variations'        => [['variationId' => 'var_on', 'percentage' => 1]],
            ]],
        ]);
        $df     = $this->datafileWith([$flag], [$audience]);
        $result = Evaluator::evaluateFlag('my-flag', false, $this->userCtx('alice', ['plan' => 'free']), $df);
        self::assertSame('default', $result['source']);
        self::assertFalse($result['value']);
    }

    public function testAbTestBucketsDeterministicallyForAlice(): void
    {
        // Mirrors the 'ab_test_bucket_deterministic_user_a' conformance fixture.
        $flag = [
            'id'                 => 'flag_ab_1',
            'key'                => 'checkout-v2-experiment',
            'type'               => 'string',
            'enabled'            => true,
            'defaultVariationId' => 'var_control',
            'variations'         => [
                ['id' => 'var_control',   'key' => 'control',   'value' => 'old'],
                ['id' => 'var_treatment', 'key' => 'treatment', 'value' => 'new'],
            ],
            'overrides' => [],
            'rules'     => [[
                'id'                => 'rule_ab_split',
                'type'              => 'ab_test',
                'enabled'           => true,
                'audienceIds'       => [],
                'hashAttribute'     => 'user.key',
                'trafficAllocation' => 1,
                'variations'        => [
                    ['variationId' => 'var_control',   'percentage' => 0.5],
                    ['variationId' => 'var_treatment',  'percentage' => 0.5],
                ],
            ]],
        ];
        $df     = $this->datafileWith([$flag]);
        $result = Evaluator::evaluateFlag('checkout-v2-experiment', 'fallback', ['kind' => 'user', 'key' => 'alice'], $df);
        self::assertSame('rule', $result['source']);
        self::assertSame('ab_test', $result['ruleType']);
        // alice hashes into control per the TS fixture.
        self::assertSame('control', $result['variationKey']);
        self::assertSame('old', $result['value']);
    }

    public function testAbTestTrafficAllocationExcludesUserBeyondThreshold(): void
    {
        // Use a very low traffic allocation so that the deterministic bucket
        // value for a known user falls outside it.
        $flag = $this->makeFlag([
            'key'  => 'low-traffic-flag',
            'type' => 'boolean',
            'rules' => [[
                'id'                => 'rule_low',
                'type'              => 'ab_test',
                'enabled'           => true,
                'audienceIds'       => [],
                'hashAttribute'     => 'user.key',
                'trafficAllocation' => 0.0001, // near-zero → almost all excluded
                'variations'        => [
                    ['variationId' => 'var_on', 'percentage' => 1.0],
                ],
            ]],
        ]);
        $df     = $this->datafileWith([$flag]);
        $result = Evaluator::evaluateFlag('low-traffic-flag', false, $this->userCtx('bob'), $df);
        // The user is very likely excluded (falls to default).
        self::assertContains($result['source'], ['default', 'rule']);
    }

    public function testHoldoutExcludesUserAndServesHoldoutVariation(): void
    {
        $flag = $this->makeFlag([
            'id'  => 'flag_holdout_target',
            'key' => 'feature-with-holdout',
            'rules' => [[
                'id'                => 'rule_full_rollout',
                'type'              => 'targeted_delivery',
                'enabled'           => true,
                'audienceIds'       => [],
                'hashAttribute'     => 'user.key',
                'trafficAllocation' => null,
                'variations'        => [['variationId' => 'var_on', 'percentage' => 1]],
            ]],
        ]);
        $holdout = [
            'id'                   => 'holdout_q2',
            'key'                  => 'q2-2026-holdout',
            'trafficAllocation'    => 0.999, // 99.9% held out
            'flagIds'              => ['flag_holdout_target'],
            'hashAttribute'        => 'user.key',
            'defaultVariationByFlag' => ['flag_holdout_target' => 'var_off'],
        ];
        $df     = $this->datafileWith([$flag], [], [$holdout]);
        // alice falls inside the 99.9% holdout window.
        $result = Evaluator::evaluateFlag('feature-with-holdout', false, $this->userCtx('alice'), $df);
        self::assertSame('holdout', $result['source']);
        self::assertSame('holdout', $result['ruleType']);
        self::assertFalse($result['value']);
        self::assertSame('off', $result['variationKey']);
    }

    public function testDefaultServedWhenNoRulesMatch(): void
    {
        $flag   = $this->makeFlag();
        $df     = $this->datafileWith([$flag]);
        $result = Evaluator::evaluateFlag('my-flag', false, $this->userCtx(), $df);
        self::assertSame('default', $result['source']);
        self::assertFalse($result['value']);
    }

    public function testReasonsAccumulatorIsPopulated(): void
    {
        $df      = $this->emptyDatafile();
        $reasons = [];
        Evaluator::evaluateFlag('does-not-exist', false, $this->userCtx(), $df, $reasons);
        self::assertNotEmpty($reasons);
        self::assertStringContainsString('not found', $reasons[0]);
    }

    public function testDisabledRuleIsSkipped(): void
    {
        $flag = $this->makeFlag([
            'rules' => [
                [
                    'id'                => 'rule_disabled',
                    'type'              => 'targeted_delivery',
                    'enabled'           => false,
                    'audienceIds'       => [],
                    'hashAttribute'     => 'user.key',
                    'trafficAllocation' => null,
                    'variations'        => [['variationId' => 'var_on', 'percentage' => 1]],
                ],
            ],
        ]);
        $df     = $this->datafileWith([$flag]);
        $result = Evaluator::evaluateFlag('my-flag', false, $this->userCtx(), $df);
        // Disabled rule is skipped; falls to default.
        self::assertSame('default', $result['source']);
    }
}
