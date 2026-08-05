<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Http;

/**
 * One HTTP response, whatever transport produced it.
 *
 * A transport error is a response too: status 0 with the error text, so no
 * caller has to catch anything to find out what happened.
 */
readonly class HttpResponse
{
    /**
     * @param int $status        HTTP status, or 0 when the request never landed
     * @param string $body       response body, "" on a transport error
     * @param string|null $error transport error message, null on a real response
     * @param string|null $retryAfter the Retry-After header, verbatim
     */
    public function __construct(
        public int $status,
        public string $body,
        public ?string $error = null,
        public ?string $retryAfter = null,
    ) {}

    public function isOk(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** The decoded JSON body, or null when it does not parse into an array. */
    public function json(): ?array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** A short description for a log line: "HTTP 404" or the transport error. */
    public function describe(): string
    {
        return $this->error ?? "HTTP {$this->status}";
    }
}
