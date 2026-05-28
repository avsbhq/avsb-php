<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Tests;

use Avsbhq\Avsb\Sticky\InMemoryStickyBucketService;
use PHPUnit\Framework\TestCase;

final class StickyMemoryTest extends TestCase
{
    private InMemoryStickyBucketService $service;

    protected function setUp(): void
    {
        $this->service = new InMemoryStickyBucketService();
    }

    public function testLookupReturnsNullWhenNoAssignmentExists(): void
    {
        self::assertNull($this->service->lookup('alice', 'my-flag'));
    }

    public function testSaveAndLookupRoundTrip(): void
    {
        $assignment = [
            'variationId' => 'var_on',
            'ruleId'      => 'rule_1',
            'ruleType'    => 'ab_test',
            'assignedAt'  => 1716000000000,
        ];
        $this->service->save('alice', 'my-flag', $assignment);
        $retrieved = $this->service->lookup('alice', 'my-flag');
        self::assertSame($assignment, $retrieved);
    }

    public function testDifferentUsersAreIsolated(): void
    {
        $assignment = ['variationId' => 'var_on', 'ruleId' => 'r1', 'ruleType' => 'ab_test', 'assignedAt' => 0];
        $this->service->save('alice', 'flag-a', $assignment);
        self::assertNull($this->service->lookup('bob', 'flag-a'));
    }

    public function testDifferentFlagKeysAreIsolated(): void
    {
        $assignment = ['variationId' => 'var_on', 'ruleId' => 'r1', 'ruleType' => 'ab_test', 'assignedAt' => 0];
        $this->service->save('alice', 'flag-a', $assignment);
        self::assertNull($this->service->lookup('alice', 'flag-b'));
    }

    public function testOverwriteExistingAssignment(): void
    {
        $old = ['variationId' => 'var_off', 'ruleId' => 'r1', 'ruleType' => 'ab_test', 'assignedAt' => 0];
        $new = ['variationId' => 'var_on',  'ruleId' => 'r1', 'ruleType' => 'ab_test', 'assignedAt' => 1];
        $this->service->save('alice', 'flag-a', $old);
        $this->service->save('alice', 'flag-a', $new);
        self::assertSame('var_on', $this->service->lookup('alice', 'flag-a')['variationId']);
    }
}
