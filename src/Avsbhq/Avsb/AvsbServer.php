<?php

declare(strict_types=1);

namespace Avsbhq\Avsb;

use Avsbhq\Avsb\Datafile\DatafileFetcher;
use Avsbhq\Avsb\Datafile\FileDatafileCache;
use Avsbhq\Avsb\Http\CurlTransport;
use Avsbhq\Avsb\Logger\StderrLogger;
use Avsbhq\Avsb\Sticky\StickyPipeline;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * AvsbServer: the PHP server SDK.
 *
 * Quickstart, and the whole of it:
 *
 *   $server = new AvsbServer($_ENV['AVSB_SDK_KEY']);
 *   $flag = $server->getBoolFlag('checkout-v2', false, EvalContext::user('alice'));
 *   if ($flag->value) { ... }
 *
 * The constructor loads the datafile through a TTL cache, so a warm process
 * pays a file read and a cold one pays a single CDN round trip with a timeout.
 * This SDK used to require the caller to supply a decoded datafile array,
 * which made PHP the only language where you had to build datafile delivery
 * yourself before evaluating anything.
 *
 * For high-traffic deployments the recommended production shape is still the
 * A vs B agent: a sidecar that holds the datafile, keeps it fresh over SSE,
 * and buffers events, with PHP reading from it. See the agent section of the
 * README. This built-in path is what makes the first five minutes work.
 */
final class AvsbServer
{
    // The evaluation pipeline and the event wiring live in these two traits so
    // this file stays about the client's lifecycle. Both are internal.
    use AvsbServerEvaluation;
    use AvsbServerEvents;

    /** @var array<string, mixed>|null */
    private ?array $datafile = null;

    private readonly LoggerInterface $logger;
    private readonly StickyPipeline $sticky;
    private readonly ?EventTracker $tracker;
    private readonly AvsbServerOptions $options;

    /** @var array{success: bool, source: string, error: string|null} */
    private array $initResult;

    private static bool $warnedEmptyContext = false;

    /**
     * @param string $sdkKey the environment SDK key, sdk_<environment>_<id>
     */
    public function __construct(
        private readonly string $sdkKey,
        ?AvsbServerOptions $options = null,
    ) {
        $this->options = $options ?? new AvsbServerOptions();
        $this->logger = $this->options->logger ?? self::defaultLogger();

        // Log a key that cannot be right, then carry on with it: the datafile
        // fetch is the real arbiter, and a shape we do not recognise today may
        // be valid tomorrow.
        $keyMessage = SdkKeyShape::messageFor($this->sdkKey);
        if ($keyMessage !== null) {
            $this->logger->error($keyMessage);
        }

        $this->sticky = new StickyPipeline(
            $this->options->stickyBucketService,
            $this->logger,
            $this->options->onStickyOrphan
        );

        $this->initResult = $this->loadDatafile();
        $this->tracker = $this->buildTracker();

        if ($this->options->heartbeat && $this->datafile !== null) {
            $transport = $this->options->transport ?? new CurlTransport();
            (new Heartbeat($this->sdkKey, $transport, $this->logger))->sendIfDue($this->datafile);
        }
    }

    /**
     * Build a server from a datafile you already hold: the agent or daemon
     * shape, where something else keeps it fresh. The SDK key is read from the
     * datafile, because events must carry the key of the environment they came
     * from.
     *
     * @param array<string, mixed> $datafile
     */
    public static function fromDatafile(
        array $datafile,
        ?AvsbServerOptions $options = null
    ): self {
        $base = $options ?? new AvsbServerOptions();
        $sdkKey = is_string($datafile['sdkKey'] ?? null) ? $datafile['sdkKey'] : '';

        return new self($sdkKey, new AvsbServerOptions(
            datafile: $datafile,
            logger: $base->logger,
            decisionLog: $base->decisionLog,
            transport: $base->transport,
            cache: $base->cache,
            cdnHost: $base->cdnHost,
            datafileTtlSeconds: $base->datafileTtlSeconds,
            fetchTimeoutSeconds: $base->fetchTimeoutSeconds,
            events: $base->events,
            flushEventsOnShutdown: $base->flushEventsOnShutdown,
            stickyBucketService: $base->stickyBucketService,
            banditModelStore: $base->banditModelStore,
            onStickyOrphan: $base->onStickyOrphan,
            heartbeat: $base->heartbeat,
        ));
    }

    // ------------------------------------------------------------------
    // State
    // ------------------------------------------------------------------

    /**
     * How the datafile load went.
     *
     * @return array{success: bool, source: string, error: string|null}
     *         source is "bootstrap", "cache", "network", or "error"
     */
    public function initResult(): array
    {
        return $this->initResult;
    }

    /** True when a datafile is loaded and flags are readable. */
    public function isReady(): bool
    {
        return $this->datafile !== null;
    }

    /**
     * The datafile in memory, for streaming helpers and diagnostics.
     *
     * @return array<string, mixed>|null
     */
    public function datafile(): ?array
    {
        return $this->datafile;
    }

    /**
     * Replace the datafile in memory: for a daemon that received an update, or
     * a long-running runtime refreshing on its own schedule.
     *
     * @param array<string, mixed> $datafile
     */
    public function replaceDatafile(array $datafile): void
    {
        $this->datafile = $datafile;
        $this->tracker?->setCollectEndpoint($this->collectEndpoint($datafile));
    }

    // ------------------------------------------------------------------
    // Typed getters
    // ------------------------------------------------------------------

    /**
     * Evaluate a boolean flag.
     *
     * $defaultValue is what you get when the flag is missing, the SDK never
     * loaded a datafile, or the flag is off. It comes back unchanged, with its
     * type intact.
     */
    public function getBoolFlag(
        string $key,
        bool $defaultValue,
        ?EvalContext $context = null
    ): Flag {
        return $this->evaluate($key, $defaultValue, $context);
    }

    /** Evaluate a string flag. */
    public function getStringFlag(
        string $key,
        string $defaultValue,
        ?EvalContext $context = null
    ): Flag {
        return $this->evaluate($key, $defaultValue, $context);
    }

    /** Evaluate a numeric flag. */
    public function getNumberFlag(
        string $key,
        float $defaultValue,
        ?EvalContext $context = null
    ): Flag {
        return $this->evaluate($key, $defaultValue, $context);
    }

    /**
     * Evaluate a JSON flag. The value is whatever json_decode produced for
     * that variation: an array, or a scalar.
     */
    public function getJsonFlag(
        string $key,
        mixed $defaultValue,
        ?EvalContext $context = null
    ): Flag {
        return $this->evaluate($key, $defaultValue, $context);
    }

    // ------------------------------------------------------------------
    // Events
    // ------------------------------------------------------------------

    /**
     * Record a conversion.
     *
     * Queued and sent once, after the response, from a shutdown function.
     *
     * @param string $eventName the metric key from your dashboard. An unknown
     *        key is stored, so a typo shows up as an empty metric rather than
     *        an error.
     * @param EvalContext $context whose key attributes the event to a visitor
     * @param float|null $revenue money in decimal major units, for revenue
     *        metrics. null for a non-revenue conversion.
     * @param float|null $value the number for average-value metrics. null when
     *        the metric counts.
     */
    public function track(
        string $eventName,
        EvalContext $context,
        ?float $revenue = null,
        ?float $value = null
    ): void {
        if ($this->tracker === null) {
            $this->logger->warning(
                "avsb track(\"{$eventName}\") was dropped: this server has no ingest "
                . 'endpoint, either because no datafile loaded or because events are '
                . 'switched off in AvsbServerOptions.'
            );

            return;
        }

        $this->tracker->queueMetric([
            'visitorId' => $this->visitorId($context->toArray()),
            'eventName' => $eventName,
            'revenue' => $revenue,
            'value' => $value,
            'timestamp' => round(microtime(true) * 1000),
            'pageUrl' => '',
        ]);
    }

    /** Record a purchase. Sent immediately, not batched. */
    public function trackPurchase(EvalContext $context, PurchaseOrder $order): bool
    {
        if ($this->tracker === null) {
            $this->logger->warning('avsb trackPurchase was dropped: no ingest endpoint.');

            return false;
        }

        return $this->tracker->trackPurchase($this->visitorId($context->toArray()), $order);
    }

    /** Send queued events now, instead of waiting for shutdown. */
    public function flushEvents(): void
    {
        $this->tracker?->flush();
    }

    /** Events queued and not yet sent. */
    public function pendingEventCount(): int
    {
        return $this->tracker?->pendingCount() ?? 0;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /** @return array{success: bool, source: string, error: string|null} */
    private function loadDatafile(): array
    {
        if ($this->options->datafile !== null) {
            $this->datafile = $this->options->datafile;

            return ['success' => true, 'source' => 'bootstrap', 'error' => null];
        }

        if (trim($this->sdkKey) === '') {
            $error = 'AvsB was constructed without an SDK key, so no datafile can be '
                . 'fetched and every flag returns your default value.';
            $this->logger->error($error);

            return ['success' => false, 'source' => 'error', 'error' => $error];
        }

        $fetcher = new DatafileFetcher(
            $this->options->transport ?? new CurlTransport(),
            $this->options->cache ?? new FileDatafileCache(),
            $this->options->cdnHost,
            $this->options->datafileTtlSeconds,
            $this->options->fetchTimeoutSeconds,
        );

        $result = $fetcher->fetch($this->sdkKey);
        if ($result['error'] !== null) {
            $this->logger->error($result['error']);
        }

        if ($result['datafile'] === null) {
            return ['success' => false, 'source' => 'error', 'error' => $result['error']];
        }

        $this->datafile = $result['datafile'];

        return ['success' => true, 'source' => $result['source'], 'error' => $result['error']];
    }

    /** Clears the once-per-process warning latch. For tests. */
    public static function resetProcessWarnings(): void
    {
        self::$warnedEmptyContext = false;
    }

    /**
     * Warnings on stderr in development, silence in production (spec section
     * 1.2). Production is detected from APP_ENV or SYMFONY_ENV, the two
     * conventions PHP frameworks agree on.
     */
    private static function defaultLogger(): LoggerInterface
    {
        $env = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? getenv('APP_ENV');
        $symfonyEnv = $_ENV['SYMFONY_ENV'] ?? getenv('SYMFONY_ENV');
        $value = strtolower((string) ($env !== false && $env !== null && $env !== ''
            ? $env
            : ($symfonyEnv !== false && $symfonyEnv !== null ? $symfonyEnv : '')));

        return $value === 'prod' || $value === 'production'
            ? new NullLogger()
            : new StderrLogger();
    }
}
