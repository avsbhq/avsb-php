<?php

declare(strict_types=1);

namespace Avsbhq\Avsb;

use Avsbhq\Avsb\Core\BanditModelStore;
use Avsbhq\Avsb\Datafile\DatafileCache;
use Avsbhq\Avsb\Datafile\DatafileFetcher;
use Avsbhq\Avsb\Http\HttpTransport;
use Avsbhq\Avsb\Sticky\StickyBucketService;
use Psr\Log\LoggerInterface;

/**
 * Everything optional about an AvsbServer, in one typed object.
 *
 * Every field has a working default, so `new AvsbServer($sdkKey)` is the whole
 * quickstart. Use named arguments to change one thing:
 *
 *   new AvsbServer($sdkKey, new AvsbServerOptions(logger: $logger));
 */
readonly class AvsbServerOptions
{
    /**
     * @param array<string, mixed>|null $datafile a datafile you already have.
     *        Supplied means "serve from this and never fetch", which is the
     *        agent or daemon shape: something else keeps it fresh.
     * @param LoggerInterface|null $logger PSR-3. Defaults to warnings on
     *        stderr in development and silence in production.
     * @param DecisionLog|null $decisionLog collects every decision this
     *        instance makes, for debugging or audit.
     * @param HttpTransport|null $transport how requests are made. Defaults to
     *        curl, with streams as a fallback. Pass a Psr18Transport to reuse
     *        the host application's HTTP client.
     * @param DatafileCache|null $cache where the fetched datafile is kept
     *        between requests. Defaults to the system temp directory.
     * @param string $cdnHost override the CDN origin for a proxy or local dev.
     * @param int $datafileTtlSeconds how long a cached datafile is served
     *        before a refetch. The floor on how quickly a flag change reaches
     *        this process.
     * @param float $fetchTimeoutSeconds request timeout for the datafile read.
     * @param bool $events send exposures and conversions to ingest.
     * @param bool $flushEventsOnShutdown flush queued events from a shutdown
     *        function. Turn it off only if you call flushEvents() yourself.
     * @param StickyBucketService|null $stickyBucketService keeps a user on the
     *        variation they first saw.
     * @param BanditModelStore|null $banditModelStore supplies learned bandit
     *        models. Without one, bandit rules run their cold-start policy.
     * @param callable(array<string, mixed>): void|null $onStickyOrphan called
     *        when a stored assignment points at a rule the datafile no longer
     *        carries.
     * @param bool $heartbeat report liveness to the dashboard's Environments
     *        page, throttled to once per interval per machine.
     */
    public function __construct(
        public ?array $datafile = null,
        public ?LoggerInterface $logger = null,
        public ?DecisionLog $decisionLog = null,
        public ?HttpTransport $transport = null,
        public ?DatafileCache $cache = null,
        public string $cdnHost = Endpoints::DEFAULT_CDN_HOST,
        public int $datafileTtlSeconds = DatafileFetcher::DEFAULT_TTL_SECONDS,
        public float $fetchTimeoutSeconds = DatafileFetcher::DEFAULT_TIMEOUT_SECONDS,
        public bool $events = true,
        public bool $flushEventsOnShutdown = true,
        public ?StickyBucketService $stickyBucketService = null,
        public ?BanditModelStore $banditModelStore = null,
        public mixed $onStickyOrphan = null,
        public bool $heartbeat = true,
    ) {}
}
