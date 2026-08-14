<?php

declare(strict_types=1);

namespace Avsbhq\Avsb;

use InvalidArgumentException;

/**
 * Data-plane endpoint contract (SDK/API DX overhaul spec 1.1).
 *
 * Single source of truth for every network target this SDK talks to.
 * Mirror of packages/avsb-core/src/endpoints.ts; both are locked by the
 * "endpoints" conformance fixture family
 * (packages/avsb-conformance/fixtures/endpoints/manifest.json).
 *
 * Nothing in this SDK may compose a data-plane URL by hand. Change a URL
 * here and the fixture changes with it, in the same commit, or every SDK's
 * conformance run fails.
 *
 * Credential posture:
 *  - Datafile and preview reads are keyless by path. The SDK key is the
 *    path segment: no header, no query string, no Authorization.
 *  - Event sends carry sdkKey in the JSON body envelope.
 *  - The stream ticket request is the one read that takes the key as a
 *    query parameter.
 *  - No Authorization header anywhere on the data plane.
 */
final class Endpoints
{
    /**
     * Default hosts. Overridable for self-hosted proxies, local dev, and
     * tests. Nothing here points at a host that does not exist:
     * datafile.avsb.cloud and stream.avsb.cloud are NOT real and must never
     * appear as defaults.
     */
    public const DEFAULT_CDN_HOST     = 'https://cdn.avsb.cloud';
    public const DEFAULT_COLLECT_HOST = 'https://ingest.avsb.cloud';
    public const DEFAULT_APP_HOST     = 'https://app.avsb.cloud';

    /**
     * Every ingestion path, keyed by what it carries. Appended to a collect
     * ORIGIN (see collectUrl).
     *
     * @var array<string, string>
     */
    public const COLLECT_PATHS = [
        'single'      => '/v1/collect',
        'batch'       => '/v1/collect/batch',
        'metricBatch' => '/v1/collect/metric-batch',
        'orders'      => '/v1/collect/orders',
        'errors'      => '/v1/collect/errors',
        'ping'        => '/v1/ping',
        'geo'         => '/v1/geo',
    ];

    public const STREAM_TICKET_PATH = '/api/sdk/stream';
    public const HEARTBEAT_PATH     = '/api/sdk/heartbeat';

    public const DATAFILE_FILENAME = 'datafile.json';
    public const PREVIEW_FILENAME  = 'preview.json';

    public const STREAM_UNAVAILABLE_REASON = 'Streaming is on, but there is no stream endpoint '
        . 'to connect to: the datafile carries no "streamEndpoint" field and no streamEndpoint '
        . 'option was passed. Republish the environment from the A vs B dashboard (Settings, '
        . 'then Environments) to pick up the field, or pass streamEndpoint explicitly. Polling '
        . 'stays on in the meantime, so flag changes still arrive, just on the polling interval '
        . 'instead of instantly.';

    /**
     * Canonical datafile URL: {cdnHost}/{sdkKey}/datafile.json.
     *
     * This is the ONLY correct shape. "/datafiles/{key}.json",
     * "/v1/datafiles/{key}.json", and datafile.avsb.cloud/v2/{key} were all
     * bugs and all 404.
     */
    public static function datafileUrl(string $sdkKey, string $cdnHost = self::DEFAULT_CDN_HOST): string
    {
        $key = self::requireSdkKey($sdkKey, 'datafileUrl');

        return self::trimTrailingSlashes($cdnHost) . '/' . $key . '/' . self::DATAFILE_FILENAME;
    }

    /** Per-environment preview snapshot. Served with no-store. */
    public static function previewUrl(string $sdkKey, string $cdnHost = self::DEFAULT_CDN_HOST): string
    {
        $key = self::requireSdkKey($sdkKey, 'previewUrl');

        return self::trimTrailingSlashes($cdnHost) . '/' . $key . '/' . self::PREVIEW_FILENAME;
    }

    /** CDN object key for the live datafile. */
    public static function datafileObjectKey(string $sdkKey): string
    {
        return self::requireSdkKey($sdkKey, 'datafileObjectKey') . '/' . self::DATAFILE_FILENAME;
    }

    /** CDN object key for the preview snapshot. */
    public static function previewObjectKey(string $sdkKey): string
    {
        return self::requireSdkKey($sdkKey, 'previewObjectKey') . '/' . self::PREVIEW_FILENAME;
    }

    /**
     * Normalise a datafile collectEndpoint value down to a bare origin.
     *
     * The flag datafile publishes an origin; the web-experiment datafile
     * publishes the full batch URL. Both are valid inputs, so an SDK can
     * never produce "/v1/collect/batch/v1/collect/batch".
     */
    public static function collectOriginFrom(string $collectEndpoint): string
    {
        $trimmed = self::trimTrailingSlashes(trim($collectEndpoint));
        foreach (self::COLLECT_PATHS as $path) {
            if (str_ends_with($trimmed, $path)) {
                return substr($trimmed, 0, strlen($trimmed) - strlen($path));
            }
        }

        return $trimmed;
    }

    /** Build an ingestion URL. $kind is a key of COLLECT_PATHS. */
    public static function collectUrl(string $kind, string $collectEndpoint = self::DEFAULT_COLLECT_HOST): string
    {
        if (!isset(self::COLLECT_PATHS[$kind])) {
            $known = array_keys(self::COLLECT_PATHS);
            sort($known);

            throw new InvalidArgumentException(
                'collectUrl got an unknown endpoint kind "' . $kind . '". Known kinds: '
                . implode(', ', $known) . '.'
            );
        }

        return self::collectOriginFrom($collectEndpoint) . self::COLLECT_PATHS[$kind];
    }

    /**
     * Resolve the stream ticket endpoint. Precedence: explicit option, then
     * the server-controlled datafile field, then nothing.
     *
     * Streaming is server controlled: the publisher writes streamEndpoint
     * into the datafile. With neither source available, streaming stays OFF
     * and the reason says why. Never guess a host: a default of
     * stream.avsb.cloud gave every Go customer an infinite reconnect loop
     * against DNS that does not resolve.
     *
     * @return array{available: bool, endpoint: ?string, source: ?string, reason: ?string}
     */
    public static function resolveStreamEndpoint(
        ?string $optionEndpoint = null,
        ?string $datafileStreamEndpoint = null
    ): array {
        $option = trim($optionEndpoint ?? '');
        if ($option !== '') {
            return [
                'available' => true,
                'endpoint'  => self::trimTrailingSlashes($option),
                'source'    => 'option',
                'reason'    => null,
            ];
        }

        $fromDatafile = trim($datafileStreamEndpoint ?? '');
        if ($fromDatafile !== '') {
            return [
                'available' => true,
                'endpoint'  => self::trimTrailingSlashes($fromDatafile),
                'source'    => 'datafile',
                'reason'    => null,
            ];
        }

        return [
            'available' => false,
            'endpoint'  => null,
            'source'    => null,
            'reason'    => self::STREAM_UNAVAILABLE_REASON,
        ];
    }

    /** Step 1 of streaming: the ticket request URL. */
    public static function streamTicketUrl(string $streamEndpoint, string $sdkKey): string
    {
        $key = self::requireSdkKey($sdkKey, 'streamTicketUrl');

        return self::trimTrailingSlashes($streamEndpoint) . '?sdkKey=' . rawurlencode($key);
    }

    /**
     * Step 2 of streaming: the SSE URL.
     *
     * $subscribeBaseUrl is the `url` field the TICKET RESPONSE handed back, not
     * the ticket endpoint itself. The token rides in the query string because
     * SSE clients cannot set request headers; tokens expire in 60 seconds.
     */
    public static function streamSubscribeUrl(string $subscribeBaseUrl, string $sdkKey, string $token): string
    {
        $key = self::requireSdkKey($sdkKey, 'streamSubscribeUrl');

        return self::trimTrailingSlashes($subscribeBaseUrl)
            . '?sdkKey=' . rawurlencode($key)
            . '&token=' . rawurlencode($token);
    }

    /** Fallback stream ticket URL, for docs and tests. */
    public static function streamEndpointUrl(string $appHost = self::DEFAULT_APP_HOST): string
    {
        return self::trimTrailingSlashes($appHost) . self::STREAM_TICKET_PATH;
    }

    /** Fallback heartbeat URL, for docs and tests. */
    public static function heartbeatUrl(string $appHost = self::DEFAULT_APP_HOST): string
    {
        return self::trimTrailingSlashes($appHost) . self::HEARTBEAT_PATH;
    }

    /**
     * null means "do not send heartbeats". Deliberately not defaulted to
     * app.avsb.cloud: a proxied deployment publishes its own origin, and
     * heartbeats posted to the wrong host would report liveness nowhere
     * while looking healthy.
     */
    public static function resolveHeartbeatEndpoint(?string $datafileHeartbeatEndpoint): ?string
    {
        $value = trim($datafileHeartbeatEndpoint ?? '');

        return $value === '' ? null : $value;
    }

    private static function trimTrailingSlashes(string $host): string
    {
        return rtrim($host, '/');
    }

    /**
     * Loud guard: an empty key builds
     * "https://cdn.avsb.cloud//datafile.json", which 404s at the edge with
     * nothing in the message to explain why. Full key-shape validation
     * belongs in the client constructor (spec 1.2); this only catches
     * "nothing was passed".
     */
    private static function requireSdkKey(string $sdkKey, string $fnName): string
    {
        $trimmed = trim($sdkKey);
        if ($trimmed === '') {
            throw new InvalidArgumentException(
                $fnName . ' was called without an SDK key. Pass the environment SDK key '
                . '(it looks like "sdk_production_xxxxxxxx"), which you can copy from '
                . 'Environments in the project sidebar, in the A vs B dashboard.'
            );
        }

        return $trimmed;
    }
}
