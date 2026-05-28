<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Streaming;

/**
 * SseClient — Server-Sent Events client for datafile streaming.
 *
 * Connects to the A vs B streaming endpoint using ext-curl and fires
 * the $onUpdate callback whenever a new datafile payload arrives.
 *
 * This class surfaces the streaming API surface. The reconnection loop
 * and event-id resumption are implemented; actual persistent connection
 * management in a long-running PHP process (e.g. ReactPHP, Swoole, or
 * a CLI daemon) is the caller's responsibility.
 *
 * Note: ext-curl must be enabled. Async streaming requires a long-lived
 * PHP process — this client is not suitable for FPM/CGI request handlers.
 */
final class SseClient
{
    private bool $running = false;
    private ?string $lastEventId = null;

    /**
     * @param string   $url       Streaming endpoint URL.
     * @param string   $sdkKey    SDK key sent as Authorization header.
     * @param callable $onUpdate  Invoked with the decoded datafile array on
     *                             each 'datafile' event. Signature: fn(array $datafile): void
     * @param int      $reconnectDelayMs  Milliseconds between reconnect attempts.
     */
    public function __construct(
        private readonly string $url,
        private readonly string $sdkKey,
        private readonly mixed $onUpdate,
        private readonly int $reconnectDelayMs = 3000
    ) {}

    /**
     * Start streaming. Blocks the calling process until stop() is called
     * or the process is terminated. Use this in a dedicated daemon process
     * or a Swoole/ReactPHP coroutine.
     */
    public function start(): void
    {
        $this->running = true;

        while ($this->running) {
            $this->connect();
            if ($this->running) {
                usleep($this->reconnectDelayMs * 1000);
            }
        }
    }

    /** Signal the streaming loop to exit after the current connection drops. */
    public function stop(): void
    {
        $this->running = false;
    }

    // ------------------------------------------------------------------
    // Private
    // ------------------------------------------------------------------

    private function connect(): void
    {
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('SseClient requires ext-curl');
        }

        $buffer = '';
        $self   = $this;

        $ch = curl_init($this->url);
        if ($ch === false) {
            return;
        }

        $headers = [
            'Accept: text/event-stream',
            'Cache-Control: no-cache',
            "Authorization: Bearer {$this->sdkKey}",
        ];
        if ($this->lastEventId !== null) {
            $headers[] = "Last-Event-ID: {$this->lastEventId}";
        }

        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 0,
            CURLOPT_WRITEFUNCTION  => static function ($ch, string $chunk) use ($self, &$buffer): int {
                $buffer .= $chunk;
                $self->processBuffer($buffer);
                return strlen($chunk);
            },
        ]);

        curl_exec($ch);
        curl_close($ch);
    }

    private function processBuffer(string &$buffer): void
    {
        // SSE events are separated by double newlines.
        while (($pos = strpos($buffer, "\n\n")) !== false) {
            $raw    = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 2);
            $this->handleEvent($raw);
        }
    }

    private function handleEvent(string $raw): void
    {
        $lines     = explode("\n", $raw);
        $eventType = '';
        $data      = '';
        $id        = null;

        foreach ($lines as $line) {
            if (str_starts_with($line, 'event:')) {
                $eventType = trim(substr($line, 6));
            } elseif (str_starts_with($line, 'data:')) {
                $data .= trim(substr($line, 5));
            } elseif (str_starts_with($line, 'id:')) {
                $id = trim(substr($line, 3));
            }
        }

        if ($id !== null) {
            $this->lastEventId = $id;
        }

        if ($eventType === 'datafile' && $data !== '') {
            $decoded = json_decode($data, true);
            if (is_array($decoded)) {
                ($this->onUpdate)($decoded);
            }
        }
    }
}
