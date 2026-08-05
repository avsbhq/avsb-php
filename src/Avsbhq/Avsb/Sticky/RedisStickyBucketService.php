<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Sticky;

/**
 * Redis-backed sticky assignment store.
 *
 * Works with predis/predis (composer suggest) or the ext-redis extension: pass
 * any object exposing get(string) and either setex(key, ttl, value) or
 * set(key, value, 'EX', ttl). Both satisfy that without an adapter.
 *
 * Key format:   avsb:sticky:{userId}:{flagKey}
 * Value format: JSON-encoded assignment
 *
 * The file name matches the class name, which PSR-4 requires. It used to be
 * Sticky/RedisService.php, so `new RedisStickyBucketService(...)` could never
 * autoload, and composer's own suggest text named a class nothing could load.
 */
final class RedisStickyBucketService implements StickyBucketService
{
    /**
     * @param object $client a Redis client exposing get() and set() or setex()
     * @param string $prefix key prefix
     * @param int $ttl       seconds to keep an assignment, 30 days by default
     */
    public function __construct(
        private readonly object $client,
        private readonly string $prefix = 'avsb:sticky:',
        private readonly int $ttl = 2_592_000,
    ) {}

    public function lookup(string $userId, string $flagKey): ?array
    {
        if (!method_exists($this->client, 'get')) {
            return null;
        }

        $raw = $this->client->get($this->key($userId, $flagKey));
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['variationId'])) {
            return null;
        }

        /** @var array{variationId: string, ruleId: string, ruleType: string, assignedAt: int} */
        return $decoded;
    }

    public function save(string $userId, string $flagKey, array $assignment): void
    {
        $key = $this->key($userId, $flagKey);
        $raw = json_encode($assignment);
        if ($raw === false) {
            return;
        }

        if (method_exists($this->client, 'setex')) {
            $this->client->setex($key, $this->ttl, $raw);

            return;
        }

        if (method_exists($this->client, 'set')) {
            $this->client->set($key, $raw, 'EX', $this->ttl);
        }
    }

    private function key(string $userId, string $flagKey): string
    {
        return $this->prefix . $userId . ':' . $flagKey;
    }
}
