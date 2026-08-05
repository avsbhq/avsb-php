<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Core;

/**
 * Why a flag has the value it has.
 *
 * The complete public set, locked by
 * packages/avsb-conformance/fixtures/semantics/manifest.json. Spellings outside
 * this enum must never reach a caller: `override` and `runtime_override` are
 * internal evaluator names in other ports and are mapped at the SDK boundary,
 * and `not_started`, `not_initialized`, and `uninitialized` are invented
 * not-ready spellings that must all be NotReady.
 */
enum EvaluationSource: string
{
    /** An override saved in the dashboard for this specific user. */
    case DatafileOverride = 'datafileOverride';
    /** An override set in code at runtime. */
    case RuntimeOverride = 'runtimeOverride';
    /** A stored sticky assignment was reused. */
    case Sticky = 'sticky';
    /** A targeting rule or A/B test decided this. */
    case Rule = 'rule';
    /** The context is held out of this flag's experiments. */
    case Holdout = 'holdout';
    /** A bandit rule chose this arm. */
    case Bandit = 'bandit';
    /** No rule matched, so the flag's default variation was served. */
    case Default = 'default';
    /** The datafile was read and this key is not in it. */
    case NotFound = 'not_found';
    /** The flag is switched off; its default variation was served. */
    case Disabled = 'disabled';
    /**
     * There was no datafile to look in: init has not completed, the fetch
     * failed with no bootstrap datafile, or the client was closed. Distinct
     * from NotFound, and reporting NotFound for an uninitialised SDK was the
     * bug this value closes.
     */
    case NotReady = 'not_ready';

    /**
     * The six sources that mean a real decision produced this value.
     * Everything else is a fallback, and a fallback is never "enabled".
     *
     * @return list<self>
     */
    public static function decided(): array
    {
        return [
            self::Rule,
            self::Holdout,
            self::Bandit,
            self::RuntimeOverride,
            self::DatafileOverride,
            self::Sticky,
        ];
    }

    /** True when a real decision produced the value carrying this source. */
    public function isDecision(): bool
    {
        return in_array($this, self::decided(), true);
    }

    /** True when the flag key is not known to exist. */
    public function isUnknownFlag(): bool
    {
        return $this === self::NotFound || $this === self::NotReady;
    }
}
