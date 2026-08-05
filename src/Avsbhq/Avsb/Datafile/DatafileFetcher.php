<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Datafile;

use Avsbhq\Avsb\Endpoints;
use Avsbhq\Avsb\Http\HttpResponse;
use Avsbhq\Avsb\Http\HttpTransport;

/**
 * Reads the datafile from the CDN, through a TTL cache.
 *
 * PHP was the only SDK with no fetch path at all: the constructor took a
 * pre-decoded array, so every customer had to build datafile delivery
 * themselves before they could evaluate a single flag. This is that path.
 *
 * It is deliberately synchronous and cache-first, because that is what suits a
 * per-request runtime: a warm cache costs one file read, a cold cache costs
 * one CDN round trip with a timeout, and a failure returns the reason rather
 * than throwing.
 *
 * For high-traffic deployments the agent (a sidecar that holds the datafile
 * and streams updates) is still the recommended production shape. This makes
 * the zero-configuration path work first.
 */
final class DatafileFetcher
{
    public const DEFAULT_TTL_SECONDS = 60;
    public const DEFAULT_TIMEOUT_SECONDS = 5.0;

    public function __construct(
        private readonly HttpTransport $transport,
        private readonly DatafileCache $cache,
        private readonly string $cdnHost = Endpoints::DEFAULT_CDN_HOST,
        private readonly int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
        private readonly float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
    ) {}

    /**
     * The exact URL this fetcher reads. Exposed for error messages and tests.
     */
    public function url(string $sdkKey): string
    {
        return Endpoints::datafileUrl($sdkKey, $this->cdnHost);
    }

    /**
     * Fetch, preferring a fresh cache entry.
     *
     * @return array{datafile: array<string, mixed>|null, source: string, error: string|null}
     *         source is "cache", "network", or "error"
     */
    public function fetch(string $sdkKey): array
    {
        $cached = $this->cache->get($sdkKey, $this->ttlSeconds);
        if ($cached !== null) {
            return ['datafile' => $cached, 'source' => 'cache', 'error' => null];
        }

        $url = $this->url($sdkKey);
        $response = $this->transport->get($url, $this->timeoutSeconds);

        if (!$response->isOk()) {
            // Serve a stale cache entry rather than nothing: an older datafile
            // is a better answer than every flag falling back to its default.
            $stale = $this->cache->get($sdkKey, -1);
            if ($stale !== null) {
                return [
                    'datafile' => $stale,
                    'source' => 'cache',
                    'error' => $this->failureMessage($url, $response),
                ];
            }

            return [
                'datafile' => null,
                'source' => 'error',
                'error' => $this->failureMessage($url, $response),
            ];
        }

        $parsed = $response->json();
        if ($parsed === null) {
            return [
                'datafile' => null,
                'source' => 'error',
                'error' => "AvsB datafile at {$url} was not JSON. Got "
                    . substr($response->body, 0, 120)
                    . '. A proxy or captive portal may be rewriting the response.',
            ];
        }

        $this->cache->put($sdkKey, $parsed);

        return ['datafile' => $parsed, 'source' => 'network', 'error' => null];
    }

    /**
     * Loud failure: what failed, the exact URL involved, and a command that
     * reproduces it without the SDK in the way.
     */
    private function failureMessage(string $url, HttpResponse $response): string
    {
        if ($response->status === 0) {
            return "AvsB datafile fetch to {$url} failed before it got a response "
                . "({$response->error}). Check outbound network access and any proxy or "
                . "firewall rules for cdn.avsb.cloud. Reproduce with: curl -i {$url}";
        }

        $hint = match ($response->status) {
            404 => 'A 404 means the CDN has no datafile for this SDK key. Either the key is '
                . 'wrong or the environment has never been published: open the A vs B '
                . 'dashboard, then Settings, then Environments, and publish it.',
            403 => 'A 403 usually means a proxy in front of the CDN is blocking the request.',
            default => "Status {$response->status} came from the CDN edge, not the SDK.",
        };

        return "AvsB datafile fetch to {$url} returned HTTP {$response->status}. {$hint} "
            . "Reproduce with: curl -i {$url}";
    }
}
