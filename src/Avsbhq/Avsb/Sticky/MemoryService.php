<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Sticky;

/**
 * StickyBucketService interface — PHP equivalent of §2.4 StickyBucketService.
 *
 * Sticky bucketing persists a user's variation assignment so they always
 * receive the same variation across sessions even if traffic allocations
 * change. Lookups must be synchronous (hot-path safe).
 */
interface StickyBucketService
{
    /**
     * Synchronous lookup — returns the sticky assignment array or null.
     *
     * @return array{variationId: string, ruleId: string, ruleType: string, assignedAt: int}|null
     */
    public function lookup(string $userId, string $flagKey): ?array;

    /**
     * Persist an assignment. Fire-and-forget — implementations are free to
     * buffer or write asynchronously.
     *
     * @param array{variationId: string, ruleId: string, ruleType: string, assignedAt: int} $assignment
     */
    public function save(string $userId, string $flagKey, array $assignment): void;
}

/**
 * InMemoryStickyBucketService — in-process sticky bucket store.
 *
 * Suitable for testing, CLI scripts, or single-request CLI contexts where
 * persistence is not required between PHP process invocations.
 */
final class InMemoryStickyBucketService implements StickyBucketService
{
    /** @var array<string, array<string, array>> outer key: userId, inner key: flagKey */
    private array $store = [];

    public function lookup(string $userId, string $flagKey): ?array
    {
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
