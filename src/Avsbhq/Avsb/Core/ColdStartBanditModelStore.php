<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Core;

/**
 * Hands back a zero-observation model for any bandit key.
 *
 * Bandit models are learned client-layer state, not published in the datafile,
 * so an SDK with no model store still has to decide something. It runs the
 * algorithm's documented cold-start policy against this model rather than
 * skipping the rule: a configured bandit that silently behaves as "no rules
 * matched" is the silent drop the spec bans (section 1.2). The conformance
 * corpus locks the cold-start action for every algorithm.
 *
 * @internal
 */
final class ColdStartBanditModelStore implements BanditModelStore
{
    /** Model version reported for the store-free fallback. */
    public const VERSION = 'cold-start';

    public function __construct(private readonly string $algorithm) {}

    /**
     * @return array{version: string, algorithm: string, perAction: array<string, mixed>}
     */
    public function getModel(string $banditKey): ?array
    {
        return [
            'version' => self::VERSION,
            'algorithm' => $this->algorithm,
            'perAction' => [],
        ];
    }

    /** The cold-start model is derived, not stored, so writes are dropped. */
    public function updateModel(string $banditKey, array $model): void
    {
        // Intentionally empty.
    }
}
