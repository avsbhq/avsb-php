<?php

declare(strict_types=1);

namespace Avsbhq\Avsb;

use Avsbhq\Avsb\Http\HttpTransport;
use Psr\Log\LoggerInterface;

/**
 * Batched event sender, built for a per-request runtime.
 *
 * Mirrors the semantics of packages/avsb-node/src/serverTracker.ts, because
 * exposures and conversions from a PHP process must land in the same columns,
 * with the same field names, as the ones from a Node process. The difference
 * is when the batch leaves: PHP has no event loop and no timer, so instead of
 * flushing on an interval this registers a shutdown function and sends
 * everything once, after the response has been produced.
 *
 * That shape matters. Sending each event inline would put a network round trip
 * inside the request; buffering with no shutdown hook would drop every event
 * the moment the process exited, which is what the old log-and-drop stub
 * effectively did.
 *
 * The body field names are frozen vocabulary
 * (packages/avsb-conformance/fixtures/wire/manifest.json). A renamed field is
 * not a rejected request: it is data that looks delivered and is gone.
 */
final class EventTracker
{
    public const DEFAULT_MAX_QUEUE_SIZE = 500;
    public const DEFAULT_BATCH_SIZE = 100;
    public const DEFAULT_RETRY_ATTEMPTS = 2;
    public const DEFAULT_TIMEOUT_SECONDS = 3.0;

    /** @var list<array<string, mixed>> */
    private array $exposures = [];

    /** @var list<array<string, mixed>> */
    private array $metrics = [];

    private bool $shutdownRegistered = false;
    private bool $closed = false;

    public function __construct(
        private readonly string $sdkKey,
        private string $collectEndpoint,
        private readonly HttpTransport $transport,
        private readonly LoggerInterface $logger,
        private readonly int $maxQueueSize = self::DEFAULT_MAX_QUEUE_SIZE,
        private readonly int $batchSize = self::DEFAULT_BATCH_SIZE,
        private readonly int $retryAttempts = self::DEFAULT_RETRY_ATTEMPTS,
        private readonly float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        private readonly bool $flushOnShutdown = true,
    ) {}

    /** Point the tracker at a new ingest origin after a datafile refresh. */
    public function setCollectEndpoint(string $collectEndpoint): void
    {
        $this->collectEndpoint = $collectEndpoint;
    }

    /**
     * Queue an exposure (an ab_test, bandit, or holdout decision).
     *
     * @param array<string, mixed> $event wire-shaped, without eventId
     */
    public function queueExposure(array $event): void
    {
        if ($this->closed) {
            return;
        }
        $this->exposures[] = $this->stamp($event);
        $this->trim($this->exposures, 'Event');
        $this->registerShutdownFlush();
    }

    /**
     * Queue a metric event (a conversion).
     *
     * @param array<string, mixed> $event wire-shaped, without eventId
     */
    public function queueMetric(array $event): void
    {
        if ($this->closed) {
            return;
        }
        $this->metrics[] = $this->stamp($event);
        $this->trim($this->metrics, 'Metric');
        $this->registerShutdownFlush();
    }

    /**
     * Send one order immediately.
     *
     * Orders are not batched or deferred to shutdown: a purchase is the event
     * a customer least wants buffered in a process that might be killed.
     *
     * @return bool true when ingest accepted it
     */
    public function trackPurchase(string $visitorId, PurchaseOrder $order): bool
    {
        if ($this->closed) {
            return false;
        }

        $payload = $order->toWireArray() + [
            'eventId' => $this->eventId(),
            'visitorId' => $visitorId,
            'timestamp' => $this->nowMs(),
            'pageUrl' => '',
        ];

        return $this->send(
            Endpoints::collectUrl('orders', $this->collectEndpoint),
            ['sdkKey' => $this->sdkKey, 'orders' => [$payload]],
            1
        );
    }

    /** Events queued and not yet sent. */
    public function pendingCount(): int
    {
        return count($this->exposures) + count($this->metrics);
    }

    /** Send everything queued, in batches. */
    public function flush(): void
    {
        if ($this->exposures === [] && $this->metrics === []) {
            return;
        }

        $exposures = $this->exposures;
        $metrics = $this->metrics;
        $this->exposures = [];
        $this->metrics = [];

        foreach (array_chunk($exposures, $this->batchSize) as $chunk) {
            $this->send(
                Endpoints::collectUrl('batch', $this->collectEndpoint),
                ['sdkKey' => $this->sdkKey, 'events' => $chunk],
                count($chunk)
            );
        }
        foreach (array_chunk($metrics, $this->batchSize) as $chunk) {
            $this->send(
                Endpoints::collectUrl('metricBatch', $this->collectEndpoint),
                ['sdkKey' => $this->sdkKey, 'metricEvents' => $chunk],
                count($chunk)
            );
        }
    }

    /** Flush and refuse further events. */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->flush();
        $this->closed = true;
    }

    // ------------------------------------------------------------------
    // Private
    // ------------------------------------------------------------------

    /**
     * Registered on the first queued event rather than in the constructor, so
     * a request that evaluates no flags adds no shutdown work at all.
     */
    private function registerShutdownFlush(): void
    {
        if ($this->shutdownRegistered || !$this->flushOnShutdown) {
            return;
        }
        $this->shutdownRegistered = true;
        register_shutdown_function(function (): void {
            if (!$this->closed) {
                $this->flush();
            }
        });
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function stamp(array $event): array
    {
        // eventId is stamped here, at enqueue. The same value is serialised on
        // every send attempt, so a retried batch carries the same ids and
        // ingestion deduplicates instead of double-counting.
        if (!isset($event['eventId'])) {
            $event['eventId'] = $this->eventId();
        }

        return $event;
    }

    /**
     * Keep the newest events.
     *
     * @param list<array<string, mixed>> $queue
     */
    private function trim(array &$queue, string $label): void
    {
        $overflow = count($queue) - $this->maxQueueSize;
        if ($overflow <= 0) {
            return;
        }

        $queue = array_slice($queue, $overflow);
        $this->logger->warning(
            "{$label} queue full ({$this->maxQueueSize}), dropped {$overflow} oldest "
            . 'event(s). Ingest may be unreachable, or this request produced more events '
            . 'than one batch can carry.'
        );
    }

    /**
     * One request, retried on the shared backoff curve. The body is serialised
     * once so every attempt carries identical eventIds.
     *
     * @param array<string, mixed> $body
     */
    private function send(string $url, array $body, int $count): bool
    {
        $payload = json_encode($body);
        if ($payload === false) {
            $this->logger->error('avsb could not encode an event batch, dropping it', [
                'endpoint' => $url,
            ]);

            return false;
        }

        $backoff = null;
        for ($attempt = 0; $attempt <= $this->retryAttempts; $attempt++) {
            $response = $this->transport->post($url, $payload, $this->timeoutSeconds);
            if ($response->isOk()) {
                return true;
            }

            if ($attempt < $this->retryAttempts) {
                $backoff = Backoff::next(
                    $backoff,
                    Backoff::parseRetryAfter($response->retryAfter)
                );
                // A short sleep inside a shutdown function is acceptable; the
                // response has already been sent to the browser by then.
                usleep((int) round($backoff->delay * 1_000_000));
                continue;
            }

            $this->logger->error(
                "avsb failed to send {$count} event(s) to {$url} after "
                . "{$this->retryAttempts} retries ({$response->describe()}). Reproduce with: "
                . "curl -i -X POST {$url} -H 'Content-Type: application/json' -d '{$payload}'"
            );
        }

        return false;
    }

    private function eventId(): string
    {
        // UUID v4 from random_bytes: no ext-uuid, no composer dependency.
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private function nowMs(): float
    {
        return round(microtime(true) * 1000);
    }
}
