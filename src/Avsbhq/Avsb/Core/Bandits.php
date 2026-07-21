<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Core;

/**
 * Bandit evaluation engine: PHP port of bandits.ts from @avsbhq/core.
 *
 * Design invariants (do not regress):
 *   - Algorithms are pure functions of (perAction, actions, hashBucket?).
 *   - No random(): every stochastic step is seeded from murmurhash3 so a
 *     (banditKey, context) pair produces a stable decision.
 *   - Tie-breaking is alphabetical on action id, also for determinism.
 */
final class Bandits
{
    private const MAX_BUCKET  = 10000;
    private const HASH_RANGE  = 0x100000000; // 2^32

    // ------------------------------------------------------------------
    // Public algorithm entry-points
    // ------------------------------------------------------------------

    /**
     * Epsilon-greedy selection.
     *
     * @param array $modelPerAction  ['actionId' => ['mean'=>float,'variance'=>float,'samples'=>int]]
     * @param array $actions         Array of BanditAction arrays.
     * @param int   $hashBucket      0..9999
     * @param float $explorationRate 0..1 (default 0.1)
     *
     * @return array{action: array, actionProbability: float, exploreFired: bool}
     */
    public static function epsilonGreedy(
        array $modelPerAction,
        array $actions,
        int $hashBucket,
        float $explorationRate = 0.1
    ): array {
        if (empty($actions)) {
            throw new \InvalidArgumentException('epsilonGreedy: actions list is empty');
        }

        $sorted  = self::sortedActions($actions);
        $n       = count($sorted);
        $epsilon = $explorationRate;
        $bucketFraction = $hashBucket / self::MAX_BUCKET;
        $exploreFired = $bucketFraction < $epsilon;

        if ($exploreFired) {
            $idx    = $hashBucket % $n;
            $action = $sorted[$idx];
            return [
                'action'            => $action,
                'actionProbability' => $epsilon / $n,
                'exploreFired'      => true,
            ];
        }

        // Exploit branch — pick the highest mean, alphabetical tie-break.
        $bestId   = null;
        $bestMean = PHP_FLOAT_EPSILON * -1e300;
        $bestCount = 0;

        foreach ($sorted as $action) {
            $stats = self::statsFor($modelPerAction, $action['id']);
            $mean  = $stats['mean'];
            if ($mean > $bestMean) {
                $bestMean  = $mean;
                $bestId    = $action['id'];
                $bestCount = 1;
            } elseif ($mean === $bestMean) {
                $bestCount++;
            }
        }

        $action      = self::findById($sorted, $bestId);
        $exploitMass = (1 - $epsilon) / $bestCount;

        return [
            'action'            => $action,
            'actionProbability' => $exploitMass + $epsilon / $n,
            'exploreFired'      => false,
        ];
    }

    /**
     * Thompson sampling: seeded, deterministic.
     *
     * @param array $modelPerAction
     * @param array $actions
     * @param int   $hashBucket
     *
     * @return array{action: array, actionProbability: float}
     */
    public static function thompsonSample(
        array $modelPerAction,
        array $actions,
        int $hashBucket
    ): array {
        if (empty($actions)) {
            throw new \InvalidArgumentException('thompsonSample: actions list is empty');
        }

        $sorted   = self::sortedActions($actions);
        $bestId   = $sorted[0]['id'];
        $bestSample = -INF;

        foreach ($sorted as $action) {
            $stats  = self::statsFor($modelPerAction, $action['id']);
            $seed   = Hash::murmurhash3("{$hashBucket}:{$action['id']}");
            $z      = self::seededGaussian($seed);
            $stddev = sqrt($stats['variance'] / max($stats['samples'], 1));
            $sample = $stats['mean'] + $z * $stddev;
            if ($sample > $bestSample) {
                $bestSample = $sample;
                $bestId     = $action['id'];
            }
        }

        return [
            'action'            => self::findById($sorted, $bestId),
            'actionProbability' => 1.0 / count($sorted),
        ];
    }

    /**
     * UCB1 selection.
     *
     * @param array $modelPerAction
     * @param array $actions
     *
     * @return array{action: array, actionProbability: float}
     */
    public static function ucb1(array $modelPerAction, array $actions): array
    {
        if (empty($actions)) {
            throw new \InvalidArgumentException('ucb1: actions list is empty');
        }

        $sorted = self::sortedActions($actions);

        // Cold-start: serve the first unseen action.
        foreach ($sorted as $action) {
            $stats = self::statsFor($modelPerAction, $action['id']);
            if ($stats['samples'] === 0) {
                return ['action' => $action, 'actionProbability' => 1.0];
            }
        }

        $totalSamples = 0;
        foreach ($sorted as $action) {
            $totalSamples += self::statsFor($modelPerAction, $action['id'])['samples'];
        }
        $lnTotal = log(max($totalSamples, 1));

        $bestId    = $sorted[0]['id'];
        $bestScore = -INF;

        foreach ($sorted as $action) {
            $stats = self::statsFor($modelPerAction, $action['id']);
            $score = $stats['mean'] + sqrt((2 * $lnTotal) / max($stats['samples'], 1));
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestId    = $action['id'];
            }
        }

        return ['action' => self::findById($sorted, $bestId), 'actionProbability' => 1.0];
    }

    /**
     * Seeded Gaussian: Box-Muller from two murmurhash3 seeds.
     * Exported so tests can assert the seeded pipeline directly.
     */
    public static function seededGaussian(int $seed): float
    {
        $u1 = self::uniformFromSeed($seed, 1);
        $u2 = self::uniformFromSeed($seed, 2);
        return sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);
    }

    /**
     * Top-level bandit evaluator.
     *
     * @param string             $banditKey
     * @param array              $config    BanditConfig array.
     * @param array              $context   EvalContext array.
     * @param BanditModelStore   $store
     *
     * @return array{action: array, banditEntry: array}|null
     */
    public static function evaluateBandit(
        string $banditKey,
        array $config,
        array $context,
        BanditModelStore $store
    ): ?array {
        $startedAt = microtime(true);
        $model     = $store->getModel($banditKey);
        if ($model === null) {
            return null;
        }

        $bucketKey   = self::deriveContextBucket($config, $context);
        $perActionMap = ($bucketKey !== '' && isset($model['contextualModels'][$bucketKey]))
            ? $model['contextualModels'][$bucketKey]
            : $model['perAction'];

        $hashSeed   = Context::bucketingKey($context);
        $hashBucket = Hash::murmurhash3("{$banditKey}:{$hashSeed}") % self::MAX_BUCKET;

        $algorithm = $config['algorithm'] ?? 'epsilon-greedy';
        $actions   = $config['actions'] ?? [];

        switch ($algorithm) {
            case 'epsilon-greedy':
                $result          = self::epsilonGreedy(
                    $perActionMap,
                    $actions,
                    $hashBucket,
                    (float) ($config['explorationRate'] ?? 0.1)
                );
                $action          = $result['action'];
                $actionProb      = $result['actionProbability'];
                $optimalityGap   = $result['exploreFired']
                    ? (float) ($config['explorationRate'] ?? 0.1)
                    : null;
                break;

            case 'thompson-sampling':
                $result        = self::thompsonSample($perActionMap, $actions, $hashBucket);
                $action        = $result['action'];
                $actionProb    = $result['actionProbability'];
                $optimalityGap = null;
                break;

            case 'ucb1':
                $result        = self::ucb1($perActionMap, $actions);
                $action        = $result['action'];
                $actionProb    = $result['actionProbability'];
                $optimalityGap = null;
                break;

            default:
                throw new \InvalidArgumentException(
                    "evaluateBandit: unknown algorithm {$algorithm}"
                );
        }

        $durationMicros = (int) max(0, round((microtime(true) - $startedAt) * 1_000_000));

        $banditEntry = [
            'flagKey'           => $banditKey,
            'value'             => $action['variationId'] ?? null,
            'variationKey'      => null,
            'source'            => 'bandit',
            'ruleId'            => null,
            'ruleType'          => 'bandit',
            'reasons'           => ["bandit \"{$banditKey}\" served action \"{$action['id']}\""],
            'evaluatedAt'       => (int) round(microtime(true) * 1000),
            'durationMicros'    => $durationMicros,
            'contextSummary'    => Context::contextSummary($context),
            'banditKey'         => $banditKey,
            'actionId'          => $action['id'],
            'modelVersion'      => $model['version'] ?? '0',
            'actionProbability' => $actionProb,
            'optimalityGap'     => $optimalityGap,
        ];

        return ['action' => $action, 'banditEntry' => $banditEntry];
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    /** @return array{mean: float, variance: float, samples: int} */
    private static function statsFor(array $perAction, string $actionId): array
    {
        if (isset($perAction[$actionId]) && is_array($perAction[$actionId])) {
            $s = $perAction[$actionId];
            return [
                'mean'     => (float) ($s['mean'] ?? 0),
                'variance' => (float) ($s['variance'] ?? 1),
                'samples'  => (int) ($s['samples'] ?? 0),
            ];
        }
        return ['mean' => 0.0, 'variance' => 1.0, 'samples' => 0];
    }

    /** Sort actions alphabetically by id — returns a new array. */
    private static function sortedActions(array $actions): array
    {
        $copy = $actions;
        usort($copy, static fn ($a, $b) => strcmp($a['id'] ?? '', $b['id'] ?? ''));
        return array_values($copy);
    }

    private static function findById(array $sorted, ?string $id): array
    {
        foreach ($sorted as $a) {
            if (($a['id'] ?? null) === $id) {
                return $a;
            }
        }
        return $sorted[0];
    }

    private static function uniformFromSeed(int $seed, int $salt): float
    {
        $hashed = Hash::murmurhash3("{$seed}:{$salt}");
        // (hashed + 1) / (2^32 + 1) gives a value in (0, 1].
        return ($hashed + 1) / (self::HASH_RANGE + 1);
    }

    /**
     * Derive a context-bucket string for contextual model lookup.
     * Returns '' when no contextAttributes are declared on any action.
     */
    private static function deriveContextBucket(array $config, array $context): string
    {
        $keys = [];
        foreach ($config['actions'] ?? [] as $action) {
            foreach (array_keys($action['contextAttributes'] ?? []) as $key) {
                $keys[$key] = true;
            }
        }
        if (empty($keys)) {
            return '';
        }

        ksort($keys);
        $parts = [];
        foreach (array_keys($keys) as $key) {
            $value  = self::resolveContextAttr($context, $key);
            $parts[] = "{$key}={$value}";
        }
        return implode(';', $parts);
    }

    private static function resolveContextAttr(array $ctx, string $key): string
    {
        $segments = explode('.', $key);
        $cursor   = $ctx;
        foreach ($segments as $seg) {
            if (!is_array($cursor)) {
                return '';
            }
            $cursor = $cursor[$seg] ?? null;
        }
        if ($cursor === null) {
            return '';
        }
        if (is_string($cursor) || is_numeric($cursor)) {
            return (string) $cursor;
        }
        return '';
    }
}
