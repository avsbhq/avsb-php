<?php

declare(strict_types=1);

namespace Avsbhq\Avsb;

use Avsbhq\Avsb\Http\HttpTransport;
use Psr\Log\LoggerInterface;

/**
 * SDK liveness.
 *
 * The dashboard's Environments page shows Connected, Stale, or Not connected
 * from these, so an SDK that never sends one looks offline no matter how well
 * it works.
 *
 * The endpoint is server controlled: it comes from the datafile's
 * heartbeatEndpoint and there is no default. A proxied deployment publishes its
 * own origin, and a heartbeat posted to the wrong host would report liveness
 * nowhere while looking healthy.
 *
 * In PHP the throttle is the whole design. A per-request runtime would
 * otherwise send one heartbeat per request, which on a busy site is thousands
 * a minute to report something that changes hourly. The send marker rides in
 * the datafile cache directory, so the throttle holds across processes.
 */
final class Heartbeat
{
    public const DEFAULT_INTERVAL_SECONDS = 900;
    public const REQUEST_TIMEOUT_SECONDS = 2.0;

    public function __construct(
        private readonly string $sdkKey,
        private readonly HttpTransport $transport,
        private readonly LoggerInterface $logger,
        private readonly ?string $markerDirectory = null,
        private readonly int $intervalSeconds = self::DEFAULT_INTERVAL_SECONDS,
    ) {}

    /**
     * Send one heartbeat if the datafile names an endpoint and enough time has
     * passed since the last send by any process on this machine.
     *
     * A failed heartbeat is logged at debug and never retried: liveness is a
     * nicety, and the next request will try again.
     *
     * @param array<string, mixed>|null $datafile
     * @return bool true when a request was made and accepted
     */
    public function sendIfDue(?array $datafile): bool
    {
        $endpoint = Endpoints::resolveHeartbeatEndpoint(
            is_array($datafile) && isset($datafile['heartbeatEndpoint'])
                && is_string($datafile['heartbeatEndpoint'])
                    ? $datafile['heartbeatEndpoint']
                    : null
        );
        if ($endpoint === null || !$this->claimSendSlot()) {
            return false;
        }

        $body = json_encode([
            'sdkKey' => $this->sdkKey,
            'sdkType' => Version::SDK_TYPE,
            'sdkVersion' => Version::VERSION,
        ]);
        if ($body === false) {
            return false;
        }

        $response = $this->transport->post($endpoint, $body, self::REQUEST_TIMEOUT_SECONDS);
        if ($response->isOk()) {
            return true;
        }

        $this->logger->debug('avsb heartbeat was not accepted', [
            'endpoint' => $endpoint,
            'result' => $response->describe(),
        ]);

        return false;
    }

    /**
     * Take the send slot for this interval, or report that another process
     * already has it. Touching the marker before sending means a failing
     * endpoint cannot turn into a request per request.
     */
    private function claimSendSlot(): bool
    {
        // Defaults alongside the datafile cache, so one writable directory
        // covers both.
        $directory = rtrim($this->markerDirectory ?? sys_get_temp_dir() . '/avsb-datafile', '/');

        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            // Nowhere to record the marker: send at most nothing rather than
            // once per request.
            return false;
        }

        $marker = $directory . '/' . hash('sha256', $this->sdkKey) . '.heartbeat';
        if (is_file($marker) && (time() - (int) @filemtime($marker)) < $this->intervalSeconds) {
            return false;
        }

        return @touch($marker) !== false;
    }
}
