<?php

declare(strict_types=1);

namespace Avsbhq\Avsb;

use Avsbhq\Avsb\Core\EvaluationSource;
use Avsbhq\Avsb\Core\RuleType;

/**
 * Flag: readonly value object returned from every get*Flag call.
 *
 * Mirrors §2.2 Flag<T> from types.ts. All fields are readonly. The two
 * convenience accessors (isEnabled / exists) match the TS interface exactly.
 */
readonly class Flag
{
    /**
     * @param mixed              $value          The variation value.
     * @param string|null        $variationKey   Variation key, or null for default/not-found.
     * @param EvaluationSource   $source         Why this value was produced.
     * @param string|null        $ruleId         Matched rule id, or null.
     * @param RuleType|null      $ruleType       Matched rule type, or null.
     * @param string[]           $reasons        Structured reason strings.
     * @param int                $evaluatedAt    ms-epoch when evaluated.
     * @param int                $durationMicros µs elapsed in the evaluator.
     */
    public function __construct(
        public mixed $value,
        public ?string $variationKey,
        public EvaluationSource $source,
        public ?string $ruleId,
        public ?RuleType $ruleType,
        public array $reasons,
        public int $evaluatedAt,
        public int $durationMicros,
    ) {}

    /**
     * Convenience: served by a rule and the value is truthy.
     * Mirrors `isEnabled()` in the TS Flag interface.
     */
    public function isEnabled(): bool
    {
        return $this->variationKey !== null && (bool) $this->value;
    }

    /**
     * Convenience: source !== 'not_found'.
     * Mirrors `exists()` in the TS Flag interface.
     */
    public function exists(): bool
    {
        return $this->source !== EvaluationSource::NotFound;
    }

    /**
     * Build a Flag instance from the raw evaluator result array.
     *
     * @internal Used by AvsbServer; not part of the public API contract.
     */
    public static function fromEvaluatorResult(array $result, int $durationMicros): self
    {
        $rawSource = $result['source'] ?? 'not_found';
        // Map the legacy 'override' source onto the public enum value.
        $source = match ($rawSource) {
            'override'        => EvaluationSource::DatafileOverride,
            'runtime_override'=> EvaluationSource::RuntimeOverride,
            'sticky'          => EvaluationSource::Sticky,
            'rule'            => EvaluationSource::Rule,
            'holdout'         => EvaluationSource::Holdout,
            'bandit'          => EvaluationSource::Bandit,
            'default'         => EvaluationSource::Default,
            'not_found'       => EvaluationSource::NotFound,
            'disabled'        => EvaluationSource::Disabled,
            default           => EvaluationSource::NotFound,
        };

        $rawRuleType = $result['ruleType'] ?? null;
        $ruleType = match ($rawRuleType) {
            'targeted_delivery' => RuleType::TargetedDelivery,
            'ab_test'           => RuleType::AbTest,
            'holdout'           => RuleType::Holdout,
            'bandit'            => RuleType::Bandit,
            default             => null,
        };

        return new self(
            value:          $result['value'] ?? null,
            variationKey:   $result['variationKey'] ?? null,
            source:         $source,
            ruleId:         $result['ruleId'] ?? null,
            ruleType:       $ruleType,
            reasons:        $result['reasons'] ?? [],
            evaluatedAt:    (int) round(microtime(true) * 1000),
            durationMicros: $durationMicros,
        );
    }
}
