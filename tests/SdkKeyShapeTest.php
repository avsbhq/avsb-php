<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Tests;

use Avsbhq\Avsb\SdkKeyShape;
use PHPUnit\Framework\TestCase;

final class SdkKeyShapeTest extends TestCase
{
    public function testItAcceptsRealKeys(): void
    {
        self::assertTrue(SdkKeyShape::isValid('sdk_production_ttqm0eaj4vth1krcb2xn'));
        // Environment keys may themselves contain underscores.
        self::assertTrue(SdkKeyShape::isValid('sdk_staging_eu_ttqm0eaj4vth1krcb2xn'));
    }

    public function testItRejectsTheValuesPeopleActuallyPasteByMistake(): void
    {
        self::assertFalse(SdkKeyShape::isValid(''));
        self::assertFalse(SdkKeyShape::isValid(null));
        self::assertFalse(SdkKeyShape::isValid('nonsense'));
        self::assertFalse(SdkKeyShape::isValid('sdk_production_abc'));
        self::assertFalse(SdkKeyShape::isValid('avsb_pat_abcdefghijklmnop'));
        self::assertFalse(SdkKeyShape::isValid('https://app.avsb.cloud/projects/p_1/settings'));
        self::assertFalse(SdkKeyShape::isValid(' sdk_production_ttqm0eaj4vth1krcb2xn '));
    }

    public function testTheMessageNamesTheMistake(): void
    {
        self::assertNull(SdkKeyShape::messageFor('sdk_production_ttqm0eaj4vth1krcb2xn'));
        self::assertStringContainsString(
            'looks like a URL',
            (string) SdkKeyShape::messageFor('https://app.avsb.cloud/projects/p_1')
        );
        self::assertStringContainsString(
            'looks like an API token',
            (string) SdkKeyShape::messageFor('avsb_pat_abcdefghijklmnop')
        );
        self::assertStringContainsString(
            'whitespace',
            (string) SdkKeyShape::messageFor(' sdk_production_ttqm0eaj4vth1krcb2xn ')
        );
        self::assertStringContainsString(
            'never existed',
            (string) SdkKeyShape::messageFor('sdk-abc123def456')
        );
        self::assertStringContainsString(
            'no SDK key was passed at all',
            (string) SdkKeyShape::messageFor('')
        );
    }

    public function testTheMessageSaysTheSdkCarriesOnRegardless(): void
    {
        self::assertStringContainsString(
            'keep going with the value you passed',
            (string) SdkKeyShape::messageFor('nonsense')
        );
    }

    public function testDescribeKeepsTheEnvironmentHalfAndRedactsTheId(): void
    {
        self::assertSame(
            'sdk_producti...b2xn',
            SdkKeyShape::describe('sdk_production_ttqm0eaj4vth1krcb2xn')
        );
        self::assertSame('(empty)', SdkKeyShape::describe(null));
    }
}
