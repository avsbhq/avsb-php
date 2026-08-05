<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Datafile;

/**
 * Where a fetched datafile is kept between PHP requests.
 *
 * This is what makes the built-in fetcher usable in a per-request runtime: a
 * PHP process usually serves one request and exits, so without a cache every
 * request would pay a CDN round trip before it could evaluate anything.
 */
interface DatafileCache
{
    /**
     * The cached datafile for this key, or null when absent or expired.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $sdkKey, int $ttlSeconds): ?array;

    /** @param array<string, mixed> $datafile */
    public function put(string $sdkKey, array $datafile): void;
}
