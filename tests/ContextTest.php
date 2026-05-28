<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Tests;

use Avsbhq\Avsb\Core\Context;
use Avsbhq\Avsb\EvalContext;
use PHPUnit\Framework\TestCase;

final class ContextTest extends TestCase
{
    public function testIsMultiContextReturnsFalseForSingleContext(): void
    {
        $ctx = ['kind' => 'user', 'key' => 'alice'];
        self::assertFalse(Context::isMultiContext($ctx));
    }

    public function testIsMultiContextReturnsTrueForMultiContext(): void
    {
        $ctx = [
            'kind' => 'multi',
            'user' => ['kind' => 'user', 'key' => 'alice'],
        ];
        self::assertTrue(Context::isMultiContext($ctx));
    }

    public function testGetContextByKindReturnsSingleContextWhenKindMatches(): void
    {
        $ctx = ['kind' => 'user', 'key' => 'alice', 'plan' => 'pro'];
        $sub = Context::getContextByKind($ctx, 'user');
        self::assertNotNull($sub);
        self::assertSame('alice', $sub['key']);
    }

    public function testGetContextByKindReturnsNullForMismatchedKind(): void
    {
        $ctx = ['kind' => 'user', 'key' => 'alice'];
        self::assertNull(Context::getContextByKind($ctx, 'organization'));
    }

    public function testGetContextByKindReturnsSubContextFromMulti(): void
    {
        $ctx = [
            'kind'         => 'multi',
            'user'         => ['kind' => 'user', 'key' => 'alice'],
            'organization' => ['kind' => 'organization', 'key' => 'acme', 'tier' => 'enterprise'],
        ];
        $org = Context::getContextByKind($ctx, 'organization');
        self::assertNotNull($org);
        self::assertSame('enterprise', $org['tier']);
    }

    public function testResolveDottedAttributeHandlesUserDotKey(): void
    {
        $ctx = ['kind' => 'user', 'key' => 'alice'];
        self::assertSame('alice', Context::resolveDottedAttribute($ctx, 'user.key'));
    }

    public function testResolveDottedAttributeHandlesMultiContextOrgTier(): void
    {
        $ctx = [
            'kind'         => 'multi',
            'user'         => ['kind' => 'user', 'key' => 'alice'],
            'organization' => ['kind' => 'organization', 'key' => 'acme', 'tier' => 'enterprise'],
        ];
        self::assertSame('enterprise', Context::resolveDottedAttribute($ctx, 'organization.tier'));
    }

    public function testResolveDottedAttributeReturnsNullForMissingKind(): void
    {
        $ctx = ['kind' => 'user', 'key' => 'alice'];
        self::assertNull(Context::resolveDottedAttribute($ctx, 'organization.tier'));
    }

    public function testResolveDottedAttributeLegacyUserIdNonDotted(): void
    {
        $ctx = ['kind' => 'user', 'key' => 'alice'];
        self::assertSame('alice', Context::resolveDottedAttribute($ctx, 'userId'));
    }

    public function testContextSummaryForSingleContext(): void
    {
        $ctx     = ['kind' => 'user', 'key' => 'alice', 'secret' => 'hidden'];
        $summary = Context::contextSummary($ctx);
        self::assertSame([['kind' => 'user', 'key' => 'alice']], $summary);
    }

    public function testContextSummaryForMultiContext(): void
    {
        $ctx = [
            'kind'         => 'multi',
            'user'         => ['kind' => 'user', 'key' => 'alice'],
            'organization' => ['kind' => 'organization', 'key' => 'acme'],
        ];
        $summary = Context::contextSummary($ctx);
        self::assertCount(2, $summary);
        $kinds = array_column($summary, 'kind');
        self::assertContains('user', $kinds);
        self::assertContains('organization', $kinds);
    }

    public function testEvalContextUserFactory(): void
    {
        $ctx = EvalContext::user('alice', ['plan' => 'pro']);
        self::assertFalse($ctx->isMulti());
        self::assertSame('alice', $ctx->toArray()['key']);
        self::assertSame('pro', $ctx->toArray()['plan']);
    }

    public function testEvalContextMultiFactory(): void
    {
        $ctx = EvalContext::multi([
            'user'         => ['key' => 'alice'],
            'organization' => ['key' => 'acme', 'tier' => 'enterprise'],
        ]);
        self::assertTrue($ctx->isMulti());
        $arr = $ctx->toArray();
        self::assertSame('multi', $arr['kind']);
        self::assertSame('enterprise', $arr['organization']['tier']);
    }
}
