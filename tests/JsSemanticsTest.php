<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Tests;

use Avsbhq\Avsb\Core\JsSemantics;
use PHPUnit\Framework\TestCase;

final class JsSemanticsTest extends TestCase
{
    /**
     * The conformance corpus records reason text exactly, including threshold
     * numbers. A plain PHP string cast is at the mercy of the `precision` ini
     * setting, and would print "1.0E+3" or a rounded value where the reference
     * prints "1000".
     */
    public function testNumberToStringPrintsIntegralValuesWithoutADecimalPoint(): void
    {
        self::assertSame('1000', JsSemantics::numberToString(0.1 * 10000));
        self::assertSame('3330', JsSemantics::numberToString(0.333 * 10000));
        self::assertSame('5000', JsSemantics::numberToString(5000.0));
        self::assertSame('10000', JsSemantics::numberToString(10000));
    }

    public function testNumberToStringKeepsTheFractionOnAFractionalThreshold(): void
    {
        self::assertSame('1234.5', JsSemantics::numberToString(0.12345 * 10000));
        self::assertSame('6234.5', JsSemantics::numberToString(6234.5));
    }

    public function testNumberToStringPrintsNanAndInfinitiesTheJavaScriptWay(): void
    {
        self::assertSame('NaN', JsSemantics::numberToString(NAN));
        self::assertSame('Infinity', JsSemantics::numberToString(INF));
        self::assertSame('-Infinity', JsSemantics::numberToString(-INF));
    }

    public function testToNumberParsesNumericStrings(): void
    {
        self::assertSame(12.0, JsSemantics::toNumber('12'));
        self::assertSame(12.5, JsSemantics::toNumber(' 12.5 '));
        self::assertSame(-3.0, JsSemantics::toNumber('-3'));
        self::assertSame(1000.0, JsSemantics::toNumber('1e3'));
    }

    /**
     * PHP's (float) cast turns "abc" into 0.0, which would make
     * `greater_than -1` match a text attribute. JavaScript's Number("abc") is
     * NAN and every comparison against it is false.
     */
    public function testToNumberTreatsNonNumericStringsAsNotANumber(): void
    {
        self::assertNull(JsSemantics::toNumber('abc'));
        self::assertNull(JsSemantics::toNumber('12abc'));
        self::assertNull(JsSemantics::toNumber(null));
        self::assertNull(JsSemantics::toNumber(NAN));
        self::assertNull(JsSemantics::toNumber([]));
    }

    public function testToNumberTreatsEmptyStringsAsZeroAsJavaScriptDoes(): void
    {
        self::assertSame(0.0, JsSemantics::toNumber(''));
        self::assertSame(0.0, JsSemantics::toNumber('   '));
    }

    public function testNumberPairIsNullWhenEitherSideIsNotANumber(): void
    {
        self::assertSame([25.0, 18.0], JsSemantics::numberPair(25, '18'));
        self::assertNull(JsSemantics::numberPair('abc', '18'));
        self::assertNull(JsSemantics::numberPair(25, 'eighteen'));
        self::assertNull(JsSemantics::numberPair(null, '0'));
    }
}
