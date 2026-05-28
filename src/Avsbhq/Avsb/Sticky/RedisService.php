<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Sticky;

/**
 * RedisStickyBucketService — Redis-backed sticky assignment store.
 *
 * Requires either predis/predis (composer suggest) or the ext-redis PHP
 * extension. Pass any object that exposes get(string): ?string and
 * set(string, string): void through the $client parameter — both predis
 * and ext-redis satisfy this interface without additional adapters.
 *
 * Key format: "avsb:sticky:{userId}:{flagKey}"
 * Value format: JSON-encoded StickyAssignment array.
 *
 * Install predis:
 *   composer require predis/predis
 */
final class RedisStickyBucketService implements StickyBucketService
{
    /**
     * @param object $client  A Redis client exposing get() and set().
     *                        Compatible with predis/predis and ext-redis.
     * @param string $prefix  Key prefix (default: 'avsb:sticky:').
     * @param int    $ttl     TTL in seconds for each assignment (default: 30 days).
     */
    public function __construct(
        private readonly object $client,
        private readonly string $prefix = 'avsb:sticky:',
        private readonly int $ttl = 2_592_000,
    ) {}

    public function lookup(string $userId, string $flagKey): ?array
    {
        $raw = $this->client->get($this->key($userId, $flagKey));
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function save(string $userId, string $flagKey, array $assignment): void
    {
        $key = $this->key($userId, $flagKey);
        $raw = json_encode($assignment);
        // predis: $client->set($key, $raw, 'EX', $ttl)
        // ext-redis: $client->setex($key, $ttl, $raw)
        // We call setex-style when available, falling back to set.
        if (method_exists($this->client, 'setex')) {
            $this->client->setex($key, $this->ttl, $raw);
        } else {
            $this->client->set($key, $raw, 'EX', $this->ttl);
        }
    }

    private function key(string $userId, string $flagKey): string
    {
        return $this->prefix . $userId . ':' . $flagKey;
    }
}
