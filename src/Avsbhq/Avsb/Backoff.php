<?php

declare(strict_types=1);

namespace Avsbhq\Avsb;

/**
 * Exponential backoff with jitter, honouring a server-supplied Retry-After.
 *
 * Port of packages/avsb-node/src/reconnectBackoff.ts, so every SDK retries on
 * the same curve: 1s, 2s, 4s, 8s, 16s, 32s, 60s (capped), plus or minus 20
 * percent of jitter.
 *
 * The jitter is the point. A fixed retry cadence means every SDK that started
 * together hits a struggling endpoint again in lockstep and stops it
 * recovering; drifting them apart is what breaks the herd.
 */
final class Backoff
{
    public const BASE_DELAY = 1.0;   // seconds
    public const MAX_DELAY = 60.0;
    public const MIN_DELAY = 0.5;
    public const JITTER = 0.2;

    /**
     * @param int $attempt   1 for the first retry
     * @param float $delay   seconds to wait before that attempt
     */
    public function __construct(
        public readonly int $attempt,
        public readonly float $delay,
    ) {}

    /**
     * The next rung after $previous (null for the first).
     *
     * @param float|null $retryAfter seconds the server asked for
     * @param callable(): float|null $random returns a float in [0, 1);
     *        injectable so a test can assert the curve without flakiness
     */
    public static function next(
        ?self $previous,
        ?float $retryAfter = null,
        ?callable $random = null
    ): self {
        $attempt = ($previous?->attempt ?? 0) + 1;

        // An explicit Retry-After wins for this one attempt, and the counter
        // still climbs so the next miss moves up the curve.
        if ($retryAfter !== null && $retryAfter > 0) {
            return new self($attempt, $retryAfter);
        }

        $roll = $random !== null ? $random() : (mt_rand() / mt_getrandmax());
        $base = min(self::MAX_DELAY, self::BASE_DELAY * (2 ** ($attempt - 1)));
        $jitter = $base * self::JITTER * (($roll * 2) - 1);

        return new self($attempt, max(self::MIN_DELAY, $base + $jitter));
    }

    /**
     * Parse a Retry-After header value.
     *
     * Only the numeric-seconds form is supported, which is what the A vs B
     * workers emit. An HTTP-date returns null and the caller falls back to the
     * exponential schedule.
     */
    public static function parseRetryAfter(?string $headerValue): ?float
    {
        if ($headerValue === null) {
            return null;
        }
        $trimmed = trim($headerValue);
        if ($trimmed === '' || !is_numeric($trimmed)) {
            return null;
        }
        $seconds = (float) $trimmed;

        return $seconds < 0 ? null : $seconds;
    }
}
