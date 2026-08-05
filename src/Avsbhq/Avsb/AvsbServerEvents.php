<?php

declare(strict_types=1);

namespace Avsbhq\Avsb;

use Avsbhq\Avsb\Http\CurlTransport;

/**
 * Event wiring for AvsbServer: the tracker's lifecycle, the exposure hook
 * handed to the evaluator, and the sticky-hit exposure.
 *
 * A trait rather than a collaborator because every method here reads the
 * server's own state, and splitting it out keeps AvsbServer about the client's
 * lifecycle. Internal to AvsbServer; nothing else may use it.
 *
 * @internal
 */
trait AvsbServerEvents
{
    private function buildTracker(): ?EventTracker
    {
        if (!$this->options->events || $this->datafile === null) {
            return null;
        }

        return new EventTracker(
            $this->sdkKey,
            $this->collectEndpoint($this->datafile),
            $this->options->transport ?? new CurlTransport(),
            $this->logger,
            flushOnShutdown: $this->options->flushEventsOnShutdown,
        );
    }

    /** @param array<string, mixed> $datafile */
    private function collectEndpoint(array $datafile): string
    {
        $endpoint = is_string($datafile['collectEndpoint'] ?? null)
            ? $datafile['collectEndpoint']
            : '';

        return $endpoint === '' ? Endpoints::DEFAULT_COLLECT_HOST : $endpoint;
    }

    /**
     * Exposures reach ingest through the tracker. With events off the
     * evaluator is handed no hook at all, so it does not even build the
     * payload.
     *
     * @return (callable(array<string, mixed>): void)|null
     */
    private function exposureHook(): ?callable
    {
        $tracker = $this->tracker;
        if ($tracker === null) {
            return null;
        }

        return static function (array $event) use ($tracker): void {
            $tracker->queueExposure($event);
        };
    }

    /**
     * A sticky hit skips the evaluator, so the exposure it would have fired is
     * recorded here instead.
     *
     * @param array<string, mixed> $assignment
     * @param array<string, mixed> $variation
     */
    private function recordStickyExposure(
        string $flagKey,
        string $userId,
        array $assignment,
        array $variation
    ): void {
        if ($this->tracker === null || $this->datafile === null) {
            return;
        }

        $flagId = '';
        foreach ($this->datafile['flags'] ?? [] as $flag) {
            if (($flag['key'] ?? null) === $flagKey) {
                $flagId = (string) ($flag['id'] ?? '');
                break;
            }
        }

        $this->tracker->queueExposure([
            'visitorId' => $userId,
            'experimentId' => $assignment['ruleId'] ?? '',
            'variationId' => $variation['id'] ?? '',
            'eventName' => 'flag_exposure',
            'revenue' => null,
            'timestamp' => round(microtime(true) * 1000),
            'pageUrl' => '',
            'properties' => ['flagKey' => $flagKey, 'flagId' => $flagId],
        ]);
    }
}
