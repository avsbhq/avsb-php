<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Core;

/**
 * BanditModelStore interface: PHP equivalent of the TS BanditModelStore.
 *
 * Implementations are responsible for persisting and serving the per-bandit
 * statistical model that drives algorithm selection.
 */
interface BanditModelStore
{
    /**
     * Synchronous hot-path read.
     *
     * @return array|null  Decoded BanditModel array, or null when not found.
     */
    public function getModel(string $banditKey): ?array;

    /**
     * Persist an updated model. Fire-and-forget: implementations may batch
     * or persist asynchronously without affecting the evaluation hot-path.
     */
    public function updateModel(string $banditKey, array $model): void;
}
