<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Logger;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;

/**
 * Writes warnings and errors to stderr.
 *
 * The SDK's default in development. A silent-by-default SDK means a wrong key,
 * a 404 datafile, and a dropped event batch all look identical to everything
 * working, which is exactly the state this program exists to end. In
 * production the host application owns its log stream, so AvsbServer falls
 * back to a null logger there and you pass your own.
 *
 * Debug and info are dropped: warn level is the useful signal while wiring the
 * SDK up.
 */
final class StderrLogger extends AbstractLogger
{
    private const REPORTED = [
        LogLevel::WARNING,
        LogLevel::ERROR,
        LogLevel::CRITICAL,
        LogLevel::ALERT,
        LogLevel::EMERGENCY,
    ];

    /** @param array<string, mixed> $context */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        if (!in_array((string) $level, self::REPORTED, true)) {
            return;
        }

        $line = '[avsb] ' . (string) $message;
        if ($context !== []) {
            $encoded = json_encode($context);
            if ($encoded !== false) {
                $line .= ' ' . $encoded;
            }
        }

        $stream = fopen('php://stderr', 'wb');
        if ($stream === false) {
            return;
        }
        fwrite($stream, $line . PHP_EOL);
        fclose($stream);
    }
}
