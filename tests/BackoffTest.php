<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Tests;

use Avsbhq\Avsb\Backoff;
use PHPUnit\Framework\TestCase;

final class BackoffTest extends TestCase
{
    /** 0.5 means "no jitter", because the jitter term is (roll * 2 - 1). */
    private function noJitter(): callable
    {
        return static fn (): float => 0.5;
    }

    public function testItClimbsOneTwoFourEightSecondsFromTheFirstRetry(): void
    {
        $state = null;
        $delays = [];
        for ($i = 0; $i < 4; $i++) {
            $state = Backoff::next($state, null, $this->noJitter());
            $delays[] = $state->delay;
        }

        self::assertSame([1.0, 2.0, 4.0, 8.0], $delays);
        self::assertSame(4, $state->attempt);
    }

    public function testItCapsAtSixtySeconds(): void
    {
        $state = Backoff::next(new Backoff(20, 60.0), null, $this->noJitter());

        self::assertSame(60.0, $state->delay);
    }

    /**
     * The jitter is the point: a fixed cadence means every SDK that started
     * together hits a struggling endpoint again in lockstep.
     */
    public function testItSpreadsRetriesByPlusOrMinusTwentyPercent(): void
    {
        $high = Backoff::next(new Backoff(3, 4.0), null, static fn (): float => 1.0);
        $low = Backoff::next(new Backoff(3, 4.0), null, static fn (): float => 0.0);

        self::assertEqualsWithDelta(9.6, $high->delay, 0.0001);
        self::assertEqualsWithDelta(6.4, $low->delay, 0.0001);
    }

    public function testRetryAfterWinsForThatAttemptAndTheCounterStillClimbs(): void
    {
        $first = Backoff::next(null, 7.0, $this->noJitter());
        $second = Backoff::next($first, null, $this->noJitter());

        self::assertSame(7.0, $first->delay);
        self::assertSame(1, $first->attempt);
        self::assertSame(2.0, $second->delay);
    }

    public function testParseRetryAfterReadsSecondsAndRejectsWhatItCannotUse(): void
    {
        self::assertSame(7.0, Backoff::parseRetryAfter('7'));
        self::assertSame(1.5, Backoff::parseRetryAfter(' 1.5 '));
        self::assertNull(Backoff::parseRetryAfter(null));
        self::assertNull(Backoff::parseRetryAfter(''));
        self::assertNull(Backoff::parseRetryAfter('-1'));
        // The HTTP-date form is legal HTTP but not what the A vs B workers
        // emit, and guessing at it would be worse than the curve.
        self::assertNull(Backoff::parseRetryAfter('Wed, 21 Oct 2026 07:28:00 GMT'));
    }
}
