<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Logger;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * NullLogger: silently discards all log messages.
 *
 * Extends Psr\Log\AbstractLogger so the full PSR-3 interface is satisfied
 * without needing psr/log ^3's NullLogger (which may not always be present).
 */
final class NullLogger extends AbstractLogger
{
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        // Intentionally empty.
    }
}
