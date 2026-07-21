<?php

declare(strict_types=1);

namespace Avsbhq\Avsb;

use Avsbhq\Avsb\Core\Context;
use Avsbhq\Avsb\Core\Evaluator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * AvsbServer: main entry point for the PHP server SDK.
 *
 * Provides typed get*Flag methods for boolean, string, number, and JSON
 * flag types, as well as track() and identify() for event ingestion.
 *
 * The datafile is supplied as a decoded PHP array on construction. In
 * production, load it from a file, HTTP endpoint, or cache layer before
 * constructing the server instance.
 */
final class AvsbServer
{
    private array $datafile;
    private LoggerInterface $logger;
    private ?DecisionLog $decisionLog;

    /**
     * @param array                $datafile    Decoded FlagDatafile array.
     * @param LoggerInterface|null $logger      PSR-3 logger (NullLogger when omitted).
     * @param DecisionLog|null     $decisionLog Optional decision log to append entries to.
     */
    public function __construct(
        array $datafile,
        ?LoggerInterface $logger = null,
        ?DecisionLog $decisionLog = null
    ) {
        $this->datafile    = $datafile;
        $this->logger      = $logger ?? new NullLogger();
        $this->decisionLog = $decisionLog;
    }

    // ------------------------------------------------------------------
    // Typed flag getters — spec §5.3 per-call defaults
    // ------------------------------------------------------------------

    /**
     * Evaluate a boolean flag.
     *
     * @param string          $key          Flag key.
     * @param bool            $defaultValue Fallback when flag is not found or errors.
     * @param EvalContext|null $context     Per-call context override.
     */
    public function getBoolFlag(
        string $key,
        bool $defaultValue,
        ?EvalContext $context = null
    ): Flag {
        return $this->evaluate($key, $defaultValue, $context);
    }

    /**
     * Evaluate a string flag.
     */
    public function getStringFlag(
        string $key,
        string $defaultValue,
        ?EvalContext $context = null
    ): Flag {
        return $this->evaluate($key, $defaultValue, $context);
    }

    /**
     * Evaluate a numeric flag.
     */
    public function getNumberFlag(
        string $key,
        float $defaultValue,
        ?EvalContext $context = null
    ): Flag {
        return $this->evaluate($key, $defaultValue, $context);
    }

    /**
     * Evaluate a JSON flag. The returned value is a decoded PHP array/scalar.
     */
    public function getJsonFlag(
        string $key,
        mixed $defaultValue,
        ?EvalContext $context = null
    ): Flag {
        return $this->evaluate($key, $defaultValue, $context);
    }

    // ------------------------------------------------------------------
    // Event methods
    // ------------------------------------------------------------------

    /**
     * Track a metric event against the collect endpoint.
     *
     * @param string          $eventName
     * @param array           $payload    Accepts 'value', 'properties', 'context'.
     * @param EvalContext|null $context   Context for this track call.
     */
    public function track(
        string $eventName,
        array $payload = [],
        ?EvalContext $context = null
    ): void {
        // In a full implementation this would POST to $this->datafile['collectEndpoint'].
        // Surface the intent without I/O in the base library.
        $this->logger->debug('avsb.track', [
            'eventName' => $eventName,
            'payload'   => $payload,
            'context'   => $context?->toArray(),
        ]);
    }

    /**
     * Identify a context, ensuring the user is known to the collect pipeline.
     */
    public function identify(EvalContext $context): void
    {
        $this->logger->debug('avsb.identify', ['context' => $context->toArray()]);
    }

    // ------------------------------------------------------------------
    // Internal
    // ------------------------------------------------------------------

    private function evaluate(
        string $key,
        mixed $defaultValue,
        ?EvalContext $context
    ): Flag {
        $start   = hrtime(true);
        $ctxArr  = $context?->toArray() ?? ['kind' => 'user', 'key' => ''];
        $reasons = [];

        $result = Evaluator::evaluateFlag($key, $defaultValue, $ctxArr, $this->datafile, $reasons);

        $durationMicros = (int) round((hrtime(true) - $start) / 1000);
        $flag = Flag::fromEvaluatorResult($result, $durationMicros);

        if ($this->decisionLog !== null && $context !== null) {
            $privateAttrs = Context::privateAttributesFrom($ctxArr);
            $entry = DecisionLogEntry::fromFlag(
                $flag,
                $key,
                Context::contextSummary($ctxArr),
                $privateAttrs,
            );
            $this->decisionLog->append($entry);
        }

        return $flag;
    }
}
