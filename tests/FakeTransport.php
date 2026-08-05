<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Tests;

use Avsbhq\Avsb\Http\HttpResponse;
use Avsbhq\Avsb\Http\HttpTransport;

/**
 * Records every request and replays queued responses.
 *
 * Every test in this suite uses it, so nothing here ever opens a socket.
 *
 * @internal test support, not part of the published API
 */
final class FakeTransport implements HttpTransport
{
    /** @var list<array{method: string, url: string, body: string|null}> */
    public array $requests = [];

    /** @var list<HttpResponse> */
    private array $queued = [];

    public function __construct(private HttpResponse $fallback = new HttpResponse(202, ''))
    {
    }

    /** Queue one response for the next request, ahead of the fallback. */
    public function queueResponse(HttpResponse $response): void
    {
        $this->queued[] = $response;
    }

    /** Every request made to a URL ending in $suffix. */
    public function requestsTo(string $suffix): array
    {
        return array_values(array_filter(
            $this->requests,
            static fn (array $request): bool => str_ends_with($request['url'], $suffix)
        ));
    }

    /** The decoded JSON body of the first request to a URL ending in $suffix. */
    public function bodyOf(string $suffix): ?array
    {
        $matches = $this->requestsTo($suffix);
        if ($matches === [] || $matches[0]['body'] === null) {
            return null;
        }
        $decoded = json_decode($matches[0]['body'], true);

        return is_array($decoded) ? $decoded : null;
    }

    public function get(string $url, float $timeoutSeconds, array $headers = []): HttpResponse
    {
        $this->requests[] = ['method' => 'GET', 'url' => $url, 'body' => null];

        return array_shift($this->queued) ?? $this->fallback;
    }

    public function post(
        string $url,
        string $body,
        float $timeoutSeconds,
        array $headers = []
    ): HttpResponse {
        $this->requests[] = ['method' => 'POST', 'url' => $url, 'body' => $body];

        return array_shift($this->queued) ?? $this->fallback;
    }
}
