<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Core;

/**
 * BigSegmentStore interface — PHP equivalent of §2.9 BigSegmentStore.
 *
 * Big-segment stores contain pre-computed segment membership data that is
 * too large to embed inside the datafile. Implementations must be
 * synchronous in the hot-path (no promises / futures).
 */
interface BigSegmentStore
{
    /**
     * Synchronous membership check.
     *
     * @param string $segmentKey  The segment identifier.
     * @param string $contextKey  The end-user or entity key being tested.
     */
    public function isMember(string $segmentKey, string $contextKey): bool;

    /**
     * Return a map of all known segment memberships for a given context key.
     * Keyed by segmentKey, value is the membership boolean.
     *
     * @return array<string, bool>
     */
    public function membership(string $contextKey): array;

    /**
     * Health probe — returns false when the store is stale or unavailable.
     */
    public function isHealthy(): bool;
}

/**
 * InMemoryBigSegmentStore — reference in-memory implementation.
 *
 * Pre-loads all segment membership data into a nested associative array.
 * Suitable for testing and for scenarios where the full dataset fits in RAM.
 */
final class InMemoryBigSegmentStore implements BigSegmentStore
{
    /**
     * @param array<string, array<string, bool>> $data
     *   Outer key: contextKey. Inner key: segmentKey. Value: membership bool.
     */
    public function __construct(private array $data = []) {}

    public function isMember(string $segmentKey, string $contextKey): bool
    {
        return $this->data[$contextKey][$segmentKey] ?? false;
    }

    public function membership(string $contextKey): array
    {
        return $this->data[$contextKey] ?? [];
    }

    public function isHealthy(): bool
    {
        return true;
    }
}
