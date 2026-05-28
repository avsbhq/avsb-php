<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Tests;

use Avsbhq\Avsb\Core\Audience;
use PHPUnit\Framework\TestCase;

final class AudienceTest extends TestCase
{
    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function condition(string $operator, mixed $value, string $attribute = 'plan', ?string $contextKind = null): array
    {
        $c = [
            'id'        => 'c1',
            'type'      => 'CUSTOM_ATTRIBUTE',
            'attribute' => $attribute,
            'operator'  => $operator,
            'value'     => $value,
        ];
        if ($contextKind !== null) {
            $c['contextKind'] = $contextKind;
        }
        return $c;
    }

    private function audience(array $conditions, string $logic = 'AND'): array
    {
        return [
            'id'    => 'aud1',
            'rules' => [
                'id'         => 'g1',
                'logic'      => $logic,
                'conditions' => $conditions,
            ],
        ];
    }

    private function userCtx(array $attrs = []): array
    {
        return array_merge(['kind' => 'user', 'key' => 'alice'], $attrs);
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    public function testExistsOperatorMatchesWhenAttributePresent(): void
    {
        $ctx = $this->userCtx(['plan' => 'pro']);
        $aud = $this->audience([$this->condition('exists', '')]);
        self::assertTrue(Audience::evaluateAudience($aud, $ctx));
    }

    public function testExistsOperatorMissesWhenAttributeAbsent(): void
    {
        $ctx = $this->userCtx();
        $aud = $this->audience([$this->condition('exists', '')]);
        self::assertFalse(Audience::evaluateAudience($aud, $ctx));
    }

    public function testNotExistsOperatorMatchesWhenAttributeAbsent(): void
    {
        $ctx = $this->userCtx();
        $aud = $this->audience([$this->condition('not_exists', '')]);
        self::assertTrue(Audience::evaluateAudience($aud, $ctx));
    }

    public function testEqualsOperatorMatchesExactString(): void
    {
        $ctx = $this->userCtx(['plan' => 'pro']);
        $aud = $this->audience([$this->condition('equals', 'pro')]);
        self::assertTrue(Audience::evaluateAudience($aud, $ctx));
    }

    public function testEqualsOperatorMissesOnDifferentValue(): void
    {
        $ctx = $this->userCtx(['plan' => 'free']);
        $aud = $this->audience([$this->condition('equals', 'pro')]);
        self::assertFalse(Audience::evaluateAudience($aud, $ctx));
    }

    public function testNotEqualsOperatorMatchesOnDifferentValue(): void
    {
        $ctx = $this->userCtx(['plan' => 'free']);
        $aud = $this->audience([$this->condition('not_equals', 'pro')]);
        self::assertTrue(Audience::evaluateAudience($aud, $ctx));
    }

    public function testContainsOperatorCaseInsensitive(): void
    {
        $ctx = $this->userCtx(['email' => 'Alice@Example.COM']);
        $aud = $this->audience([$this->condition('contains', 'example', 'email')]);
        self::assertTrue(Audience::evaluateAudience($aud, $ctx));
    }

    public function testNotContainsOperatorMatchesWhenValueAbsent(): void
    {
        $ctx = $this->userCtx(['email' => 'alice@gmail.com']);
        $aud = $this->audience([$this->condition('not_contains', 'example', 'email')]);
        self::assertTrue(Audience::evaluateAudience($aud, $ctx));
    }

    public function testGreaterThanOperatorMatchesNumeric(): void
    {
        $ctx = $this->userCtx(['age' => 30]);
        $aud = $this->audience([$this->condition('greater_than', '25', 'age')]);
        self::assertTrue(Audience::evaluateAudience($aud, $ctx));
    }

    public function testLessThanOperatorMatchesNumeric(): void
    {
        $ctx = $this->userCtx(['score' => 5]);
        $aud = $this->audience([$this->condition('less_than', '10', 'score')]);
        self::assertTrue(Audience::evaluateAudience($aud, $ctx));
    }

    public function testInListOperatorMatchesSingleValue(): void
    {
        $ctx = $this->userCtx(['role' => 'admin']);
        $aud = $this->audience([$this->condition('in_list', ['admin', 'superuser'], 'role')]);
        self::assertTrue(Audience::evaluateAudience($aud, $ctx));
    }

    public function testInListOperatorMissesWhenNotInList(): void
    {
        $ctx = $this->userCtx(['role' => 'viewer']);
        $aud = $this->audience([$this->condition('in_list', ['admin', 'superuser'], 'role')]);
        self::assertFalse(Audience::evaluateAudience($aud, $ctx));
    }

    public function testNotInListOperatorMatchesWhenAbsent(): void
    {
        $ctx = $this->userCtx(['role' => 'viewer']);
        $aud = $this->audience([$this->condition('not_in_list', ['admin', 'superuser'], 'role')]);
        self::assertTrue(Audience::evaluateAudience($aud, $ctx));
    }

    public function testRegexOperatorMatchesPattern(): void
    {
        $ctx = $this->userCtx(['email' => 'alice@internal.acme.com']);
        $aud = $this->audience([$this->condition('regex', '\\@internal\\.', 'email')]);
        self::assertTrue(Audience::evaluateAudience($aud, $ctx));
    }

    public function testOrLogicReturnsTrueWhenAnyConditionMatches(): void
    {
        $ctx = $this->userCtx(['plan' => 'free']);
        $aud = $this->audience([
            $this->condition('equals', 'pro'),
            $this->condition('equals', 'free'),
        ], 'OR');
        self::assertTrue(Audience::evaluateAudience($aud, $ctx));
    }

    public function testAndLogicReturnsFalseWhenAnyConditionFails(): void
    {
        $ctx = $this->userCtx(['plan' => 'free', 'age' => 20]);
        $aud = $this->audience([
            $this->condition('equals', 'pro'),
            $this->condition('greater_than', '18', 'age'),
        ], 'AND');
        self::assertFalse(Audience::evaluateAudience($aud, $ctx));
    }

    public function testMultiContextKindedConditionMatchesOrgTier(): void
    {
        $ctx = [
            'kind'         => 'multi',
            'user'         => ['kind' => 'user', 'key' => 'alice'],
            'organization' => ['kind' => 'organization', 'key' => 'acme', 'tier' => 'enterprise'],
        ];
        $aud = $this->audience([$this->condition('equals', 'enterprise', 'tier', 'organization')]);
        self::assertTrue(Audience::evaluateAudience($aud, $ctx));
    }

    public function testMultiContextKindMissingReturnsFalse(): void
    {
        $ctx = [
            'kind' => 'multi',
            'user' => ['kind' => 'user', 'key' => 'alice'],
        ];
        $aud = $this->audience([$this->condition('equals', 'enterprise', 'tier', 'organization')]);
        self::assertFalse(Audience::evaluateAudience($aud, $ctx));
    }

    public function testBrowserOnlyConditionReturnsFalseServerSide(): void
    {
        $ctx = $this->userCtx();
        $aud = $this->audience([[
            'id'       => 'c1',
            'type'     => 'DEVICE',
            'operator' => 'equals',
            'value'    => 'mobile',
        ]]);
        self::assertFalse(Audience::evaluateAudience($aud, $ctx));
    }

    public function testEmptyAudienceReturnsTrue(): void
    {
        $ctx = $this->userCtx();
        self::assertTrue(Audience::evaluateAudience([], $ctx));
    }
}
