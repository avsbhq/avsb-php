<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Sticky;

/**
 * In-process sticky bucket store.
 *
 * PHP processes are usually per-request, so this survives one request unless
 * you run under a long-lived runtime. Use it in tests and CLI scripts; use the
 * Redis store when assignments must outlive the process.
 */
final class InMemoryStickyBucketService implements StickyBucketService
{
    /** @var array<string, array<string, array<string, mixed>>> userId then flagKey */
    private array $store = [];

    public function lookup(string $userId, string $flagKey): ?array
    {
        /** @var array{variationId: string, ruleId: string, ruleType: string, assignedAt: int}|null */
        return $this->store[$userId][$flagKey] ?? null;
    }

    public function save(string $userId, string $flagKey, array $assignment): void
    {
        if (!isset($this->store[$userId])) {
            $this->store[$userId] = [];
        }
        $this->store[$userId][$flagKey] = $assignment;
    }
}
