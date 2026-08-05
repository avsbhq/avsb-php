<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Tests;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Captures log lines so a test can assert what the SDK said.
 *
 * Several of these tests exist because the SDK used to say nothing at all when
 * it dropped data, so "did it warn, and did the warning name the fix" is a
 * behaviour worth asserting.
 *
 * @internal test support, not part of the published API
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $lines = [];

    /** @var list<array{level: string, message: string}> */
    public array $records = [];

    /** @param array<string, mixed> $context */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->lines[] = (string) $message;
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message];
    }

    /** Every logged message containing $needle. */
    public function linesContaining(string $needle): array
    {
        return array_values(array_filter(
            $this->lines,
            static fn (string $line): bool => str_contains($line, $needle)
        ));
    }
}
