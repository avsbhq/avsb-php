<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Http;

/**
 * How this SDK makes an HTTP request.
 *
 * Deliberately our own two-method contract rather than PSR-18's
 * ClientInterface, because psr/http-client is not a dependency of this package
 * and adding one to read a JSON file would be a poor trade. Psr18Transport
 * adapts a PSR-18 client when the host application already has one, and
 * CurlTransport is the zero-dependency fallback.
 *
 * Implementations never throw: a failure is an HttpResponse with status 0.
 */
interface HttpTransport
{
    /** @param array<string, string> $headers */
    public function get(string $url, float $timeoutSeconds, array $headers = []): HttpResponse;

    /** @param array<string, string> $headers */
    public function post(
        string $url,
        string $body,
        float $timeoutSeconds,
        array $headers = []
    ): HttpResponse;
}
