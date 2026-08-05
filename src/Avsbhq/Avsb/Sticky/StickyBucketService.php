<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Sticky;

/**
 * A store that keeps a user on the variation they first saw.
 *
 * Lookups are on the evaluation hot path, so they must be synchronous. Saves
 * are fire and forget: an implementation is free to buffer or write
 * asynchronously.
 *
 * Declared in its own file because PSR-4 maps one type per file. This
 * interface and InMemoryStickyBucketService used to share a
 * Sticky/MemoryService.php, so neither was autoloadable and every
 * `new InMemoryStickyBucketService()` was a fatal error at runtime.
 */
interface StickyBucketService
{
    /**
     * @return array{variationId: string, ruleId: string, ruleType: string, assignedAt: int}|null
     */
    public function lookup(string $userId, string $flagKey): ?array;

    /**
     * @param array{variationId: string, ruleId: string, ruleType: string, assignedAt: int} $assignment
     */
    public function save(string $userId, string $flagKey, array $assignment): void;
}
