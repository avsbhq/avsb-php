<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Tests;

use Avsbhq\Avsb\Core\Holdouts;
use PHPUnit\Framework\TestCase;

final class HoldoutsTest extends TestCase
{
    private function datafile(array $holdouts): array
    {
        return [
            'version'         => 2,
            'flags'           => [],
            'audiences'       => [],
            'holdouts'        => $holdouts,
            'projectType'     => 'FEATURE_FLAG',
            'sdkKey'          => 'test',
            'environmentKey'  => 'test',
            'publishedAt'     => '',
            'collectEndpoint' => '',
        ];
    }

    private function holdout(array $overrides = []): array
    {
        return array_merge([
            'id'                   => 'holdout_1',
            'key'                  => 'h1',
            'trafficAllocation'    => 0.999,
            'flagIds'              => ['flag_a'],
            'hashAttribute'        => 'user.key',
            'defaultVariationByFlag' => ['flag_a' => 'var_off'],
        ], $overrides);
    }

    // ------------------------------------------------------------------

    public function testNoHoldoutsReturnsFalse(): void
    {
        $df     = $this->datafile([]);
        $result = Holdouts::evaluateHoldout(['kind' => 'user', 'key' => 'alice'], 'flag_a', $df);
        self::assertFalse($result['heldOut']);
    }

    public function testHoldoutNotParticipatingInFlagIsSkipped(): void
    {
        $h  = $this->holdout(['flagIds' => ['other_flag']]);
        $df = $this->datafile([$h]);
        $result = Holdouts::evaluateHoldout(['kind' => 'user', 'key' => 'alice'], 'flag_a', $df);
        self::assertFalse($result['heldOut']);
    }

    public function testZeroTrafficAllocationNeverHoldsOut(): void
    {
        $h  = $this->holdout(['trafficAllocation' => 0.0]);
        $df = $this->datafile([$h]);
        $result = Holdouts::evaluateHoldout(['kind' => 'user', 'key' => 'alice'], 'flag_a', $df);
        self::assertFalse($result['heldOut']);
    }

    public function testFullTrafficAllocationAlwaysHoldsOut(): void
    {
        $h  = $this->holdout(['trafficAllocation' => 1.0]);
        $df = $this->datafile([$h]);
        $result = Holdouts::evaluateHoldout(['kind' => 'user', 'key' => 'alice'], 'flag_a', $df);
        self::assertTrue($result['heldOut']);
    }

    public function testHeldOutResultContainsHoldoutAndVariationId(): void
    {
        $h  = $this->holdout(['trafficAllocation' => 1.0]);
        $df = $this->datafile([$h]);
        $result = Holdouts::evaluateHoldout(['kind' => 'user', 'key' => 'alice'], 'flag_a', $df);
        self::assertTrue($result['heldOut']);
        self::assertSame('holdout_1', $result['holdout']['id']);
        self::assertSame('var_off', $result['variationId']);
    }

    public function testHoldoutIsDeterministicForSameUser(): void
    {
        $h  = $this->holdout(['trafficAllocation' => 0.5]);
        $df = $this->datafile([$h]);
        $ctx = ['kind' => 'user', 'key' => 'alice'];
        $r1 = Holdouts::evaluateHoldout($ctx, 'flag_a', $df);
        $r2 = Holdouts::evaluateHoldout($ctx, 'flag_a', $df);
        self::assertSame($r1['heldOut'], $r2['heldOut']);
    }

    public function testMultipleHoldoutsFirstMatchWins(): void
    {
        $h1 = $this->holdout(['id' => 'h1', 'key' => 'holdout-first', 'trafficAllocation' => 1.0, 'flagIds' => ['flag_a']]);
        $h2 = $this->holdout(['id' => 'h2', 'key' => 'holdout-second', 'trafficAllocation' => 0.0, 'flagIds' => ['flag_a']]);
        $df = $this->datafile([$h1, $h2]);
        $result = Holdouts::evaluateHoldout(['kind' => 'user', 'key' => 'alice'], 'flag_a', $df);
        self::assertTrue($result['heldOut']);
        self::assertSame('h1', $result['holdout']['id']);
    }
}
