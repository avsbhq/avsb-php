<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Sticky;

use Avsbhq\Avsb\Core\EvaluationSource;
use Avsbhq\Avsb\Core\RuleType;
use Psr\Log\LoggerInterface;

/**
 * Sticky bucketing, wired at the CLIENT layer.
 *
 * The evaluator stays a pure function of datafile plus context; assignments
 * are read before it runs and written after it returns, exactly as
 * packages/avsb-node/src/AvsbServer.ts does it. The pipeline order is locked
 * by packages/avsb-conformance/fixtures/semantics/manifest.json:
 *
 *   1. runtime override   (client)
 *   2. sticky lookup      (client, here)
 *   3. datafile override  (evaluator)
 *   4. holdout            (evaluator)
 *   5. rules, in order    (evaluator)
 *   6. flag default       (evaluator)
 *
 * Before this, the PHP SDK had sticky classes and no pipeline at all, so
 * configuring a store did nothing.
 */
final class StickyPipeline
{
    /** @param callable(array<string, mixed>): void|null $onOrphan */
    public function __construct(
        private readonly ?StickyBucketService $service,
        private readonly LoggerInterface $logger,
        private readonly mixed $onOrphan = null,
    ) {}

    public function isEnabled(): bool
    {
        return $this->service !== null;
    }

    /**
     * Look up a stored assignment.
     *
     * @param array<string, mixed> $datafile
     * @param list<string> $reasons appended to on a hit
     * @param callable(array<string, mixed>, array<string, mixed>): void|null $onExposure
     *        called with (assignment, variation) when the exposure should fire
     *
     * @return array<string, mixed>|null an evaluator-shaped result on a hit,
     *         null on a miss. A miss is normal, not an error.
     */
    public function lookup(
        array $datafile,
        string $flagKey,
        string $userId,
        array &$reasons,
        ?callable $onExposure = null
    ): ?array {
        if ($this->service === null) {
            return null;
        }

        try {
            $assignment = $this->service->lookup($userId, $flagKey);
        } catch (\Throwable $e) {
            $this->logger->warning('avsb sticky lookup failed', [
                'flagKey' => $flagKey,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (!is_array($assignment) || !isset($assignment['variationId'])) {
            return null;
        }

        $flag = $this->findFlag($datafile, $flagKey);
        if ($flag === null) {
            return null;
        }

        // An assignment pointing at a deleted variation is a MISS, not an
        // error: the variation is gone, so serving it is impossible.
        $variation = null;
        foreach ($flag['variations'] ?? [] as $candidate) {
            if (($candidate['id'] ?? null) === $assignment['variationId']) {
                $variation = $candidate;
                break;
            }
        }
        if ($variation === null) {
            return null;
        }

        $reasons[] = "matched sticky bucket assignment for userId=\"{$userId}\"";

        if ($this->isOrphaned($flag, $assignment, $variation)) {
            // Serve the stored value, but suppress the exposure: the rule it
            // would be attributed to is gone, so the event would land against
            // an experiment that no longer exists.
            $this->logger->warning('avsb sticky assignment references a missing rule', [
                'flagKey' => $flagKey,
                'userId' => $userId,
                'assignedRuleId' => $assignment['ruleId'] ?? null,
                'assignedVariationId' => $variation['id'] ?? null,
            ]);
            $this->notifyOrphan($flagKey, $userId, $assignment);
        } elseif ($onExposure !== null) {
            $onExposure($assignment, $variation);
        }

        return [
            'value' => $variation['value'] ?? null,
            'variationKey' => $variation['key'] ?? null,
            'source' => EvaluationSource::Sticky->value,
            'ruleId' => $assignment['ruleId'] ?? null,
            'ruleType' => $assignment['ruleType'] ?? null,
            'flagKey' => $flagKey,
            'reasons' => $reasons,
        ];
    }

    /**
     * Persist an assignment, if this decision is one worth remembering.
     *
     * Only an ab_test rule match is: a targeted_delivery is a rollout, not an
     * assignment, and holdouts, overrides, and defaults are recomputed every
     * time by design.
     *
     * @param array<string, mixed> $datafile
     * @param array<string, mixed> $result the evaluator result
     */
    public function save(array $datafile, string $flagKey, string $userId, array $result): void
    {
        if ($this->service === null) {
            return;
        }
        if (($result['source'] ?? null) !== EvaluationSource::Rule->value) {
            return;
        }
        if (($result['ruleType'] ?? null) !== RuleType::AbTest->value) {
            return;
        }
        if (!is_string($result['variationKey'] ?? null) || !is_string($result['ruleId'] ?? null)) {
            return;
        }

        $flag = $this->findFlag($datafile, $flagKey);
        if ($flag === null) {
            return;
        }

        foreach ($flag['variations'] ?? [] as $variation) {
            if (($variation['key'] ?? null) !== $result['variationKey']) {
                continue;
            }

            try {
                // The variation's ID, never its key: lookup resolves by id, so
                // saving the key writes a row that can never match again.
                $this->service->save($userId, $flagKey, [
                    'variationId' => (string) $variation['id'],
                    'ruleId' => (string) $result['ruleId'],
                    'ruleType' => RuleType::AbTest->value,
                    'assignedAt' => (int) round(microtime(true) * 1000),
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('avsb sticky save failed', [
                    'flagKey' => $flagKey,
                    'error' => $e->getMessage(),
                ]);
            }

            return;
        }
    }

    /**
     * @param array<string, mixed> $datafile
     * @return array<string, mixed>|null
     */
    private function findFlag(array $datafile, string $flagKey): ?array
    {
        foreach ($datafile['flags'] ?? [] as $flag) {
            if (($flag['key'] ?? null) === $flagKey) {
                return $flag;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $flag
     * @param array<string, mixed> $assignment
     * @param array<string, mixed> $variation
     */
    private function isOrphaned(array $flag, array $assignment, array $variation): bool
    {
        foreach ($flag['rules'] ?? [] as $rule) {
            if (($rule['id'] ?? null) !== ($assignment['ruleId'] ?? null)) {
                continue;
            }
            foreach ($rule['variations'] ?? [] as $ruleVariation) {
                if (($ruleVariation['variationId'] ?? null) === ($variation['id'] ?? null)) {
                    return false;
                }
            }

            return true;
        }

        return true;
    }

    /** @param array<string, mixed> $assignment */
    private function notifyOrphan(string $flagKey, string $userId, array $assignment): void
    {
        if (!is_callable($this->onOrphan)) {
            return;
        }

        try {
            ($this->onOrphan)([
                'flagKey' => $flagKey,
                'userId' => $userId,
                'assignment' => $assignment,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('avsb onStickyOrphan callback threw', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
