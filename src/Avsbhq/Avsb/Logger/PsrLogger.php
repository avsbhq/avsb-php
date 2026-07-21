<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Logger;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Stringable;

/**
 * PsrLogger: thin adapter that prefixes all messages with the SDK tag.
 *
 * Wraps any PSR-3 LoggerInterface and forwards calls with a consistent
 * '[avsb]' prefix in the message so SDK log lines are filterable.
 */
final class PsrLogger implements LoggerInterface
{
    public function __construct(
        private readonly LoggerInterface $inner,
        private readonly string $prefix = '[avsb]'
    ) {}

    public function emergency(string|Stringable $message, array $context = []): void
    {
        $this->inner->emergency($this->prefixed($message), $context);
    }

    public function alert(string|Stringable $message, array $context = []): void
    {
        $this->inner->alert($this->prefixed($message), $context);
    }

    public function critical(string|Stringable $message, array $context = []): void
    {
        $this->inner->critical($this->prefixed($message), $context);
    }

    public function error(string|Stringable $message, array $context = []): void
    {
        $this->inner->error($this->prefixed($message), $context);
    }

    public function warning(string|Stringable $message, array $context = []): void
    {
        $this->inner->warning($this->prefixed($message), $context);
    }

    public function notice(string|Stringable $message, array $context = []): void
    {
        $this->inner->notice($this->prefixed($message), $context);
    }

    public function info(string|Stringable $message, array $context = []): void
    {
        $this->inner->info($this->prefixed($message), $context);
    }

    public function debug(string|Stringable $message, array $context = []): void
    {
        $this->inner->debug($this->prefixed($message), $context);
    }

    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->inner->log($level, $this->prefixed($message), $context);
    }

    private function prefixed(string|Stringable $message): string
    {
        return "{$this->prefix} {$message}";
    }
}
