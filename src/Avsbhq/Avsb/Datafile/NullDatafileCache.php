<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Datafile;

/**
 * Caches nothing.
 *
 * Use it when something else already holds the datafile (an agent, a shared
 * Redis, an opcache-warmed constant) and a second copy on disk would only add
 * a staleness question. Every construction then pays one CDN read.
 */
final class NullDatafileCache implements DatafileCache
{
    public function get(string $sdkKey, int $ttlSeconds): ?array
    {
        return null;
    }

    public function put(string $sdkKey, array $datafile): void
    {
        // Intentionally empty.
    }
}
