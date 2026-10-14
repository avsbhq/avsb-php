<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Tests;

use Avsbhq\Avsb\AvsbServer;
use Avsbhq\Avsb\AvsbServerOptions;
use Avsbhq\Avsb\Datafile\NullDatafileCache;
use Avsbhq\Avsb\DecisionLog;
use Avsbhq\Avsb\EvalContext;
use Avsbhq\Avsb\Http\HttpResponse;
use Avsbhq\Avsb\PurchaseOrder;
use Avsbhq\Avsb\Sticky\InMemoryStickyBucketService;
use PHPUnit\Framework\TestCase;

/**
 * The client: what it fetches, what it records, and what it says when it
 * cannot do either.
 */
final class AvsbServerTest extends TestCase
{
    private const SDK_KEY = 'sdk_production_clientfixture01';

    protected function setUp(): void
    {
        AvsbServer::resetProcessWarnings();
    }

    /**
     * An ab_test rule at 100 percent allocation with a single 100 percent
     * variation, so every context is bucketed and an exposure always fires.
     *
     * @return array<string, mixed>
     */
    private function datafile(): array
    {
        return [
            'version' => 2,
            'projectType' => 'FEATURE_FLAG',
            'sdkKey' => self::SDK_KEY,
            'environmentKey' => 'production',
            'publishedAt' => '2026-05-29T00:00:00.000Z',
            'collectEndpoint' => 'https://ingest.avsb.cloud',
            'heartbeatEndpoint' => 'https://app.avsb.cloud/api/sdk/heartbeat',
            'flags' => [[
                'id' => 'flag_1',
                'key' => 'checkout-v2',
                'type' => 'boolean',
                'enabled' => true,
                'defaultVariationId' => 'var_off',
                'variations' => [
                    ['id' => 'var_off', 'key' => 'off', 'value' => false],
                    ['id' => 'var_on', 'key' => 'on', 'value' => true],
                ],
                'overrides' => [],
                'rules' => [[
                    'id' => 'rule_ab',
                    'type' => 'ab_test',
                    'enabled' => true,
                    'audienceIds' => [],
                    'hashAttribute' => 'user.key',
                    'trafficAllocation' => 1,
                    'variations' => [['variationId' => 'var_on', 'percentage' => 1]],
                ]],
            ]],
            'audiences' => [],
        ];
    }

    private function context(): EvalContext
    {
        return EvalContext::user('alice');
    }

    /** A server serving the fixture datafile, with a transport that records. */
    private function bootstrapped(
        FakeTransport $transport,
        ?AvsbServerOptions $overrides = null
    ): AvsbServer {
        $options = $overrides ?? new AvsbServerOptions();

        return new AvsbServer(self::SDK_KEY, new AvsbServerOptions(
            datafile: $this->datafile(),
            logger: $options->logger,
            decisionLog: $options->decisionLog,
            transport: $transport,
            cache: new NullDatafileCache(),
            events: $options->events,
            flushEventsOnShutdown: false,
            stickyBucketService: $options->stickyBucketService,
            heartbeat: $options->heartbeat,
        ));
    }

    // ------------------------------------------------------------------
    // Construction
    // ------------------------------------------------------------------

    public function testItFetchesFromTheCanonicalDatafileUrl(): void
    {
        $transport = new FakeTransport(new HttpResponse(200, json_encode($this->datafile())));

        $server = new AvsbServer(self::SDK_KEY, new AvsbServerOptions(
            transport: $transport,
            cache: new NullDatafileCache(),
            events: false,
            heartbeat: false,
        ));

        self::assertTrue($server->isReady());
        self::assertSame(
            'https://cdn.avsb.cloud/' . self::SDK_KEY . '/datafile.json',
            $transport->requests[0]['url']
        );
        self::assertSame('network', $server->initResult()['source']);
    }

    public function testAValidKeyProducesNoKeyShapeComplaint(): void
    {
        $logger = new RecordingLogger();

        $server = $this->bootstrapped(new FakeTransport(), new AvsbServerOptions(
            logger: $logger,
            events: false,
            heartbeat: false,
        ));

        self::assertTrue($server->isReady());
        self::assertSame([], $logger->linesContaining('does not look like an SDK key'));
    }

    public function testAnUnusableKeyIsNamedAndEveryFlagFallsBackToItsDefault(): void
    {
        $logger = new RecordingLogger();

        $server = new AvsbServer('nonsense', new AvsbServerOptions(
            logger: $logger,
            transport: new FakeTransport(new HttpResponse(404, '')),
            cache: new NullDatafileCache(),
            events: false,
            heartbeat: false,
        ));

        self::assertFalse($server->isReady());
        self::assertFalse($server->initResult()['success']);
        self::assertNotEmpty($logger->linesContaining('does not look like an SDK key'));
        self::assertSame(
            false,
            $server->getBoolFlag('checkout-v2', false, $this->context())->value
        );
    }

    public function testA404ExplainsItselfAndPrintsAReproductionCurl(): void
    {
        $logger = new RecordingLogger();

        $server = new AvsbServer(self::SDK_KEY, new AvsbServerOptions(
            logger: $logger,
            transport: new FakeTransport(new HttpResponse(404, 'not found')),
            cache: new NullDatafileCache(),
            events: false,
            heartbeat: false,
        ));

        $error = (string) $server->initResult()['error'];
        self::assertStringContainsString('HTTP 404', $error);
        self::assertStringContainsString('publish', $error);
        self::assertStringContainsString('curl -i https://cdn.avsb.cloud/', $error);
    }

    // ------------------------------------------------------------------
    // Evaluation
    // ------------------------------------------------------------------

    public function testItReturnsTheBucketedVariationWithTheFullEnvelope(): void
    {
        $server = $this->bootstrapped(
            new FakeTransport(),
            new AvsbServerOptions(events: false, heartbeat: false)
        );

        $flag = $server->getBoolFlag('checkout-v2', false, $this->context());

        self::assertTrue($flag->value);
        self::assertSame('on', $flag->variationKey);
        self::assertSame('rule', $flag->source->value);
        self::assertSame('ab_test', $flag->ruleType?->value);
        self::assertSame('rule_ab', $flag->ruleId);
        self::assertTrue($flag->isEnabled());
        self::assertTrue($flag->exists());
        self::assertStringContainsString('matched (ab_test)', $flag->reasons[0]);
    }

    public function testItRecordsTheDecisionWhenADecisionLogIsSupplied(): void
    {
        $log = new DecisionLog();
        $server = $this->bootstrapped(
            new FakeTransport(),
            new AvsbServerOptions(decisionLog: $log, events: false, heartbeat: false)
        );

        $server->getBoolFlag('checkout-v2', false, $this->context());

        $entries = $log->getEntries();
        self::assertCount(1, $entries);
        self::assertSame('checkout-v2', $entries[0]->flagKey);
        self::assertSame([['kind' => 'user', 'key' => 'alice']], $entries[0]->contextSummary);
    }

    public function testItWarnsOncePerProcessAboutAnEmptyContextKey(): void
    {
        $logger = new RecordingLogger();
        $server = $this->bootstrapped(
            new FakeTransport(),
            new AvsbServerOptions(logger: $logger, events: false, heartbeat: false)
        );

        $server->getBoolFlag('checkout-v2', false);
        $server->getBoolFlag('checkout-v2', false);
        $server->getBoolFlag('checkout-v2', false);

        self::assertCount(1, $logger->linesContaining('empty context key'));
    }

    // ------------------------------------------------------------------
    // Events
    // ------------------------------------------------------------------

    public function testAnAbTestDecisionRecordsAnExposureWithTheLockedFieldNames(): void
    {
        $transport = new FakeTransport();
        $server = $this->bootstrapped($transport, new AvsbServerOptions(heartbeat: false));

        $server->getBoolFlag('checkout-v2', false, $this->context());
        $server->flushEvents();

        $body = $transport->bodyOf('/v1/collect/batch');
        self::assertNotNull($body, 'an exposure should have been sent');
        $event = $body['events'][0];
        self::assertSame('flag_exposure', $event['eventName']);
        self::assertSame('alice', $event['visitorId']);
        self::assertSame('rule_ab', $event['experimentId']);
        self::assertSame('var_on', $event['variationId']);
        self::assertNull($event['revenue']);
        self::assertSame('', $event['pageUrl']);
        self::assertSame(
            ['flagKey' => 'checkout-v2', 'flagId' => 'flag_1'],
            $event['properties']
        );
    }

    public function testAHoldoutDecisionRecordsItsOwnEventName(): void
    {
        $datafile = $this->datafile();
        $datafile['holdouts'] = [[
            'id' => 'holdout_q2',
            'key' => 'q2-2026-holdout',
            'trafficAllocation' => 1,
            'hashAttribute' => 'user.key',
            'flagIds' => ['flag_1'],
            'defaultVariationByFlag' => ['flag_1' => 'var_off'],
        ]];

        $transport = new FakeTransport();
        $server = new AvsbServer(self::SDK_KEY, new AvsbServerOptions(
            datafile: $datafile,
            transport: $transport,
            cache: new NullDatafileCache(),
            flushEventsOnShutdown: false,
            heartbeat: false,
        ));

        $server->getBoolFlag('checkout-v2', false, $this->context());
        $server->flushEvents();

        $event = $transport->bodyOf('/v1/collect/batch')['events'][0];
        self::assertSame('flag_holdout_exposure', $event['eventName']);
        self::assertSame('holdout_q2', $event['experimentId']);
        self::assertSame('q2-2026-holdout', $event['properties']['holdoutKey']);
    }

    public function testEventsCanBeSwitchedOffEntirely(): void
    {
        $transport = new FakeTransport();
        $server = $this->bootstrapped(
            $transport,
            new AvsbServerOptions(events: false, heartbeat: false)
        );

        $server->getBoolFlag('checkout-v2', false, $this->context());
        $server->flushEvents();

        self::assertSame([], $transport->requestsTo('/v1/collect/batch'));
    }

    public function testTrackQueuesAMetricEventKeepingRevenueAndValueApart(): void
    {
        $transport = new FakeTransport();
        $server = $this->bootstrapped($transport, new AvsbServerOptions(heartbeat: false));

        $server->track('purchase_completed', $this->context(), revenue: 49.99, value: 2.0);
        $server->flushEvents();

        $body = $transport->bodyOf('/v1/collect/metric-batch');
        self::assertNotNull($body);
        $event = $body['metricEvents'][0];
        self::assertSame('purchase_completed', $event['eventName']);
        self::assertSame('alice', $event['visitorId']);
        self::assertSame(49.99, $event['revenue']);
        // JSON has one number type, so json_encode(2.0) writes 2 and it decodes as the int 2.
        // The wire value is the number 2 either way; what must hold is that it is a number,
        // not a string, and that it is the value and not the revenue.
        self::assertTrue(is_int($event['value']) || is_float($event['value']), 'value must be a JSON number');
        self::assertEqualsWithDelta(2.0, $event['value'], 0.0);
        self::assertSame(self::SDK_KEY, $body['sdkKey']);
    }

    public function testTrackSaysSoRatherThanDroppingSilentlyWithNoDatafile(): void
    {
        $logger = new RecordingLogger();
        $server = new AvsbServer(self::SDK_KEY, new AvsbServerOptions(
            logger: $logger,
            transport: new FakeTransport(new HttpResponse(404, '')),
            cache: new NullDatafileCache(),
            heartbeat: false,
        ));

        $server->track('purchase_completed', $this->context());

        self::assertNotEmpty($logger->linesContaining('was dropped'));
    }

    public function testTrackPurchaseSendsImmediatelyToTheOrdersPath(): void
    {
        $transport = new FakeTransport();
        $server = $this->bootstrapped($transport, new AvsbServerOptions(heartbeat: false));

        $accepted = $server->trackPurchase(
            $this->context(),
            new PurchaseOrder(orderId: 'ORD-1', total: 99.95)
        );

        self::assertTrue($accepted);
        $order = $transport->bodyOf('/v1/collect/orders')['orders'][0];
        self::assertSame('ORD-1', $order['orderId']);
        self::assertSame('alice', $order['visitorId']);
    }

    // ------------------------------------------------------------------
    // Heartbeat
    // ------------------------------------------------------------------

    public function testItPostsTheLockedHeartbeatBodyAfterLoadingADatafile(): void
    {
        $transport = new FakeTransport();
        $markerDirectory = sys_get_temp_dir() . '/avsb-heartbeat-test-' . bin2hex(random_bytes(4));

        // The throttle marker lives on disk, so a unique directory per test
        // keeps one run from suppressing the next.
        (new \Avsbhq\Avsb\Heartbeat(self::SDK_KEY, $transport, new RecordingLogger(),
            $markerDirectory))->sendIfDue($this->datafile());

        $body = $transport->bodyOf('/api/sdk/heartbeat');
        self::assertNotNull($body);
        self::assertSame([
            'sdkKey' => self::SDK_KEY,
            'sdkType' => 'avsbhq/avsb',
            'sdkVersion' => \Avsbhq\Avsb\Version::VERSION,
        ], $body);
    }

    public function testItSendsAtMostOneHeartbeatPerInterval(): void
    {
        $transport = new FakeTransport();
        $markerDirectory = sys_get_temp_dir() . '/avsb-heartbeat-test-' . bin2hex(random_bytes(4));
        $heartbeat = new \Avsbhq\Avsb\Heartbeat(
            self::SDK_KEY,
            $transport,
            new RecordingLogger(),
            $markerDirectory
        );

        $heartbeat->sendIfDue($this->datafile());
        $heartbeat->sendIfDue($this->datafile());
        $heartbeat->sendIfDue($this->datafile());

        self::assertCount(1, $transport->requestsTo('/api/sdk/heartbeat'));
    }

    public function testNoHeartbeatWhenTheDatafileNamesNoEndpoint(): void
    {
        $transport = new FakeTransport();
        $datafile = $this->datafile();
        unset($datafile['heartbeatEndpoint']);

        (new \Avsbhq\Avsb\Heartbeat(self::SDK_KEY, $transport, new RecordingLogger(),
            sys_get_temp_dir() . '/avsb-heartbeat-test-' . bin2hex(random_bytes(4))))
            ->sendIfDue($datafile);

        self::assertSame([], $transport->requestsTo('/api/sdk/heartbeat'));
    }

    // ------------------------------------------------------------------
    // Sticky bucketing
    // ------------------------------------------------------------------

    public function testStickyIsConsultedBeforeTheEvaluatorAndSavedAfterAnAbTestMatch(): void
    {
        $store = new InMemoryStickyBucketService();
        $server = $this->bootstrapped(new FakeTransport(), new AvsbServerOptions(
            events: false,
            heartbeat: false,
            stickyBucketService: $store,
        ));

        $flag = $server->getBoolFlag('checkout-v2', false, $this->context());

        self::assertSame('rule', $flag->source->value);
        $saved = $store->lookup('alice', 'checkout-v2');
        self::assertNotNull($saved);
        // The variation's ID, never its key.
        self::assertSame('var_on', $saved['variationId']);
        self::assertSame('rule_ab', $saved['ruleId']);
        self::assertSame('ab_test', $saved['ruleType']);
    }

    public function testAStoredAssignmentIsServedOnTheNextEvaluation(): void
    {
        $store = new InMemoryStickyBucketService();
        $store->save('alice', 'checkout-v2', [
            'variationId' => 'var_off',
            'ruleId' => 'rule_ab',
            'ruleType' => 'ab_test',
            'assignedAt' => 1,
        ]);
        $server = $this->bootstrapped(new FakeTransport(), new AvsbServerOptions(
            events: false,
            heartbeat: false,
            stickyBucketService: $store,
        ));

        $flag = $server->getBoolFlag('checkout-v2', true, $this->context());

        self::assertSame('sticky', $flag->source->value);
        self::assertFalse($flag->value);
        self::assertSame('off', $flag->variationKey);
    }
}
