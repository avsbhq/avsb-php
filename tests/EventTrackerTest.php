<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Tests;

use Avsbhq\Avsb\EventTracker;
use Avsbhq\Avsb\Http\HttpResponse;
use Avsbhq\Avsb\Logger\NullLogger;
use Avsbhq\Avsb\PurchaseOrder;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * The tracker owns the wire contract. Field names here are frozen vocabulary
 * (packages/avsb-conformance/fixtures/wire/manifest.json): the ingestion
 * worker validates these exact names and the dashboard reads the columns they
 * land in, so a renamed field is data that looks delivered and is gone.
 */
final class EventTrackerTest extends TestCase
{
    private const SDK_KEY = 'sdk_production_trackerfixture01';

    private function tracker(
        FakeTransport $transport,
        ?AbstractLogger $logger = null,
        int $maxQueueSize = EventTracker::DEFAULT_MAX_QUEUE_SIZE,
        int $batchSize = EventTracker::DEFAULT_BATCH_SIZE,
        int $retryAttempts = EventTracker::DEFAULT_RETRY_ATTEMPTS
    ): EventTracker {
        return new EventTracker(
            self::SDK_KEY,
            'https://ingest.avsb.cloud',
            $transport,
            $logger ?? new NullLogger(),
            maxQueueSize: $maxQueueSize,
            batchSize: $batchSize,
            retryAttempts: $retryAttempts,
            timeoutSeconds: 0.01,
            // No shutdown hook in tests: each example flushes explicitly, so
            // nothing fires after PHPUnit has torn the world down.
            flushOnShutdown: false,
        );
    }

    /** @return array<string, mixed> */
    private function exposure(string $visitor = 'user_42'): array
    {
        return [
            'visitorId' => $visitor,
            'experimentId' => 'rule_ab',
            'variationId' => 'var_treatment',
            'eventName' => 'flag_exposure',
            'revenue' => null,
            'timestamp' => 1780000000000,
            'pageUrl' => '',
            'properties' => ['flagKey' => 'checkout-v2', 'flagId' => 'flag_1'],
        ];
    }

    public function testExposuresPostToTheLockedBatchPathWithSdkKeyInTheEnvelope(): void
    {
        $transport = new FakeTransport();
        $tracker = $this->tracker($transport);

        $tracker->queueExposure($this->exposure());
        $tracker->flush();

        $requests = $transport->requestsTo('/v1/collect/batch');
        self::assertCount(1, $requests);
        self::assertSame('https://ingest.avsb.cloud/v1/collect/batch', $requests[0]['url']);

        $body = $transport->bodyOf('/v1/collect/batch');
        self::assertSame(self::SDK_KEY, $body['sdkKey']);
        self::assertCount(1, $body['events']);
    }

    public function testEveryRequiredFieldIsPresentUnderItsRequiredName(): void
    {
        $transport = new FakeTransport();
        $tracker = $this->tracker($transport);

        $tracker->queueExposure($this->exposure());
        $tracker->flush();

        $event = $transport->bodyOf('/v1/collect/batch')['events'][0];
        foreach (
            ['eventId', 'visitorId', 'experimentId', 'variationId', 'eventName',
                'revenue', 'timestamp', 'pageUrl'] as $field
        ) {
            self::assertArrayHasKey($field, $event, "wire field {$field} is required");
        }
        self::assertSame('flag_exposure', $event['eventName']);
        self::assertNull($event['revenue']);
        self::assertSame('', $event['pageUrl']);
    }

    public function testEventIdIsStampedOnceSoARetriedBatchIsDeduplicable(): void
    {
        $transport = new FakeTransport();
        // First attempt fails, second succeeds.
        $transport->queueResponse(new HttpResponse(500, ''));
        $transport->queueResponse(new HttpResponse(202, ''));
        $tracker = $this->tracker($transport, retryAttempts: 1);

        $tracker->queueExposure($this->exposure());
        $tracker->flush();

        $requests = $transport->requestsTo('/v1/collect/batch');
        self::assertCount(2, $requests);
        $first = json_decode((string) $requests[0]['body'], true);
        $second = json_decode((string) $requests[1]['body'], true);
        self::assertSame($first['events'][0]['eventId'], $second['events'][0]['eventId']);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $first['events'][0]['eventId']
        );
    }

    public function testACallerSuppliedEventIdIsKept(): void
    {
        $transport = new FakeTransport();
        $tracker = $this->tracker($transport);

        $tracker->queueExposure($this->exposure() + ['eventId' => 'supplied-id']);
        $tracker->flush();

        self::assertSame(
            'supplied-id',
            $transport->bodyOf('/v1/collect/batch')['events'][0]['eventId']
        );
    }

    public function testAFlushSplitsIntoBatchSizeChunks(): void
    {
        $transport = new FakeTransport();
        $tracker = $this->tracker($transport, batchSize: 2);

        for ($i = 0; $i < 4; $i++) {
            $tracker->queueExposure($this->exposure("u{$i}"));
        }
        $tracker->flush();

        $requests = $transport->requestsTo('/v1/collect/batch');
        self::assertCount(2, $requests);
        foreach ($requests as $request) {
            self::assertCount(2, json_decode((string) $request['body'], true)['events']);
        }
    }

    public function testMetricEventsPostToTheMetricBatchPath(): void
    {
        $transport = new FakeTransport();
        $tracker = $this->tracker($transport);

        $tracker->queueMetric([
            'visitorId' => 'user_42',
            'eventName' => 'signup_completed',
            'revenue' => null,
            'value' => 1,
            'timestamp' => 1780000000000,
            'pageUrl' => '',
        ]);
        $tracker->flush();

        $body = $transport->bodyOf('/v1/collect/metric-batch');
        self::assertNotNull($body);
        self::assertSame('signup_completed', $body['metricEvents'][0]['eventName']);
        self::assertSame(self::SDK_KEY, $body['sdkKey']);
    }

    public function testOrdersSendImmediatelyWithoutWaitingForAFlush(): void
    {
        $transport = new FakeTransport();
        $tracker = $this->tracker($transport);

        $accepted = $tracker->trackPurchase('user_42', new PurchaseOrder(
            orderId: 'ORD-1',
            total: 99.95,
            currency: 'USD',
        ));

        self::assertTrue($accepted);
        $body = $transport->bodyOf('/v1/collect/orders');
        self::assertNotNull($body);
        $order = $body['orders'][0];
        self::assertSame('ORD-1', $order['orderId']);
        self::assertSame(99.95, $order['total']);
        self::assertSame('USD', $order['currency']);
        self::assertSame('user_42', $order['visitorId']);
        self::assertSame('', $order['pageUrl']);
        // Optional fields the caller did not set are omitted, not sent as null.
        self::assertArrayNotHasKey('coupon', $order);
    }

    public function testTheQueueIsBoundedAndSaysSoWhenItDrops(): void
    {
        $transport = new FakeTransport();
        $logger = new RecordingLogger();
        $tracker = $this->tracker($transport, $logger, maxQueueSize: 2, batchSize: 100);

        for ($i = 0; $i < 3; $i++) {
            $tracker->queueExposure($this->exposure("u{$i}"));
        }
        $tracker->flush();

        self::assertStringContainsString('queue full (2), dropped 1 oldest', $logger->lines[0]);
        $visitors = array_column(
            $transport->bodyOf('/v1/collect/batch')['events'],
            'visitorId'
        );
        // The newest events win.
        self::assertSame(['u1', 'u2'], $visitors);
    }

    public function testItGivesUpAfterTheRetryBudgetAndLogsAReproductionCurl(): void
    {
        $transport = new FakeTransport(new HttpResponse(500, ''));
        $logger = new RecordingLogger();
        $tracker = $this->tracker($transport, $logger, retryAttempts: 1);

        $tracker->queueExposure($this->exposure());
        $tracker->flush();

        self::assertCount(2, $transport->requestsTo('/v1/collect/batch'));
        self::assertStringContainsString('after 1 retries', $logger->lines[0]);
        self::assertStringContainsString('curl -i -X POST', $logger->lines[0]);
    }

    public function testCloseFlushesWhatIsQueuedAndRefusesMore(): void
    {
        $transport = new FakeTransport();
        $tracker = $this->tracker($transport);

        $tracker->queueExposure($this->exposure());
        self::assertSame(1, $tracker->pendingCount());

        $tracker->close();

        self::assertCount(1, $transport->requestsTo('/v1/collect/batch'));
        self::assertSame(0, $tracker->pendingCount());

        $tracker->queueExposure($this->exposure());
        self::assertSame(0, $tracker->pendingCount());
    }

    public function testItSendsToTheNewOriginAfterADatafileRefreshMovesIt(): void
    {
        $transport = new FakeTransport();
        $tracker = $this->tracker($transport);

        $tracker->setCollectEndpoint('https://events.customer.test');
        $tracker->queueExposure($this->exposure());
        $tracker->flush();

        self::assertSame(
            'https://events.customer.test/v1/collect/batch',
            $transport->requests[0]['url']
        );
    }
}
