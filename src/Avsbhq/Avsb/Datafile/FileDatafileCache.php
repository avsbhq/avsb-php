<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Datafile;

/**
 * Caches the datafile on disk, keyed by SDK key.
 *
 * The default so `new AvsbServer($sdkKey)` works out of the box: one CDN read
 * per TTL per machine, not one per request. The directory defaults to the
 * system temp directory, which every PHP deployment can write to; point it at
 * a persistent path (or a tmpfs shared by your workers) when you have one.
 *
 * Writes are atomic: a temporary file is renamed into place, so a concurrent
 * reader never sees a half-written datafile.
 */
final class FileDatafileCache implements DatafileCache
{
    private readonly string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = rtrim($directory ?? sys_get_temp_dir() . '/avsb-datafile', '/');
    }

    public function get(string $sdkKey, int $ttlSeconds): ?array
    {
        $path = $this->path($sdkKey);
        if (!is_file($path)) {
            return null;
        }

        $age = time() - (int) @filemtime($path);
        if ($ttlSeconds >= 0 && $age > $ttlSeconds) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function put(string $sdkKey, array $datafile): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o775, true)
            && !is_dir($this->directory)
        ) {
            return;
        }

        $encoded = json_encode($datafile);
        if ($encoded === false) {
            return;
        }

        $path = $this->path($sdkKey);
        $temp = $path . '.' . getmypid() . '.tmp';
        if (@file_put_contents($temp, $encoded, LOCK_EX) === false) {
            return;
        }
        if (!@rename($temp, $path)) {
            @unlink($temp);
        }
    }

    /** The cache path for a key. Exposed so an operator can find or clear it. */
    public function path(string $sdkKey): string
    {
        // The key is hashed rather than used directly: it is a path segment
        // from configuration, and a key with a slash in it must not escape the
        // cache directory.
        return $this->directory . '/' . hash('sha256', $sdkKey) . '.json';
    }
}
