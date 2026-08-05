<?php

declare(strict_types=1);

namespace Avsbhq\Avsb;

/**
 * DecisionLog: collects DecisionLogEntry values for the lifetime of a
 * request or server instance. Entries are append-only.
 */
final class DecisionLog
{
    /** @var DecisionLogEntry[] */
    private array $entries = [];

    public function append(DecisionLogEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    /** @return DecisionLogEntry[] */
    public function getEntries(): array
    {
        return $this->entries;
    }

    public function clear(): void
    {
        $this->entries = [];
    }
}
