<?php

declare(strict_types=1);

namespace Avsbhq\Avsb;

use Avsbhq\Avsb\Core\Context;
use Avsbhq\Avsb\Core\EvaluationSource;
use Avsbhq\Avsb\Core\Evaluator;

/**
 * The evaluation pipeline for AvsbServer.
 *
 * Order matters and is locked by
 * packages/avsb-conformance/fixtures/semantics/manifest.json:
 *
 *   1. no datafile        -> not_ready, with the caller's default
 *   2. sticky lookup      (client layer, here)
 *   3. datafile override  (evaluator)
 *   4. holdout            (evaluator)
 *   5. rules, in order    (evaluator)
 *   6. flag default       (evaluator)
 *
 * A trait rather than a collaborator because every method here reads the
 * server's own state. Internal to AvsbServer; nothing else may use it.
 *
 * @internal
 */
trait AvsbServerEvaluation
{
    private function evaluate(string $key, mixed $defaultValue, ?EvalContext $context): Flag
    {
        if ($this->datafile === null) {
            return $this->notReadyFlag($key, $defaultValue);
        }

        $start = hrtime(true);
        $ctxArray = $context?->toArray() ?? ['kind' => 'user', 'key' => ''];
        $reasons = [];
        $userId = $this->visitorId($ctxArray);
        $this->warnEmptyContextOnce($userId);

        $result = $this->sticky->lookup(
            $this->datafile,
            $key,
            $userId,
            $reasons,
            function (array $assignment, array $variation) use ($key, $userId): void {
                $this->recordStickyExposure($key, $userId, $assignment, $variation);
            }
        );

        if ($result === null) {
            $result = Evaluator::evaluateFlag(
                $key,
                $defaultValue,
                $ctxArray,
                $this->datafile,
                $reasons,
                $this->exposureHook(),
                $this->options->banditModelStore
            );
            $this->sticky->save($this->datafile, $key, $userId, $result);
        }

        $durationMicros = (int) round((hrtime(true) - $start) / 1000);
        $flag = Flag::fromEvaluatorResult($result, $durationMicros);
        $this->recordDecision($key, $flag, $ctxArray);

        return $flag;
    }

    /**
     * Evaluating before a datafile exists returns the caller's default with
     * source not_ready. Reporting not_found here would conflate "this flag
     * does not exist" with "this SDK never started".
     */
    private function notReadyFlag(string $flagKey, mixed $defaultValue): Flag
    {
        $this->logger->warning(
            "avsb \"{$flagKey}\" was evaluated with no datafile loaded, returning your "
            . 'default. Check initResult() for why the load failed, or pass a datafile in '
            . 'AvsbServerOptions to bootstrap from one you already have.'
        );

        return new Flag(
            value: $defaultValue,
            variationKey: null,
            source: EvaluationSource::NotReady,
            ruleId: null,
            ruleType: null,
            reasons: ['AvsB SDK is not ready: no datafile is loaded'],
            evaluatedAt: (int) round(microtime(true) * 1000),
            durationMicros: 0,
        );
    }

    /** @param array<string, mixed> $ctxArray */
    private function recordDecision(string $key, Flag $flag, array $ctxArray): void
    {
        if ($this->options->decisionLog === null) {
            return;
        }

        $this->options->decisionLog->append(DecisionLogEntry::fromFlag(
            $flag,
            $key,
            Context::contextSummary($ctxArray),
            Context::privateAttributesFrom($ctxArray),
        ));
    }

    /**
     * The bucketing identity: the user sub-context's key, falling back to the
     * first declared kind.
     *
     * @param array<string, mixed> $ctxArray
     */
    private function visitorId(array $ctxArray): string
    {
        $key = Context::bucketingKey($ctxArray, 'user');
        if ($key !== '') {
            return $key;
        }

        $primary = Context::primaryContext($ctxArray);

        return (string) ($primary['key'] ?? '');
    }

    /**
     * Once per process, name the exact call to add. Every caller who forgets
     * the context lands the whole fleet in one bucket, and the data looks
     * plausible right up to the moment someone tries to act on it.
     */
    private function warnEmptyContextOnce(string $userId): void
    {
        if ($userId !== '' || self::$warnedEmptyContext) {
            return;
        }
        self::$warnedEmptyContext = true;
        $this->logger->warning(
            'avsb evaluated a flag with an empty context key, so every caller buckets '
            . 'identically and experiment data will be meaningless. Pass a context, for '
            . 'example EvalContext::user((string) $user->id). This warning appears once '
            . 'per process.'
        );
    }
}
