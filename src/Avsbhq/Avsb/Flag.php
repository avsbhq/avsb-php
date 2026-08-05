<?php

declare(strict_types=1);

namespace Avsbhq\Avsb;

use Avsbhq\Avsb\Core\EvaluationSource;
use Avsbhq\Avsb\Core\JsSemantics;
use Avsbhq\Avsb\Core\RuleType;

/**
 * Flag: the result of one evaluation.
 *
 * Every get*Flag call returns this object. All properties are readonly and
 * public, so there are no getters to remember:
 *
 *   $flag->value            mixed             the evaluated value, your type
 *   $flag->variationKey     string|null       null for default, not_found, not_ready
 *   $flag->source           EvaluationSource  why this value
 *   $flag->ruleId           string|null       the rule or holdout that decided
 *   $flag->ruleType         RuleType|null     ab_test, targeted_delivery, holdout, bandit
 *   $flag->reasons          list<string>      the decision trail, in order
 *   $flag->evaluatedAt      int               milliseconds since the Unix epoch
 *   $flag->durationMicros   int               microseconds spent evaluating
 *   $flag->isEnabled()      bool
 *   $flag->exists()         bool
 */
readonly class Flag
{
    /**
     * @param mixed $value
     * @param string|null $variationKey
     * @param EvaluationSource $source
     * @param string|null $ruleId
     * @param RuleType|null $ruleType
     * @param list<string> $reasons
     * @param int $evaluatedAt    ms since the Unix epoch
     * @param int $durationMicros microseconds spent in the evaluator
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
     * True when a real decision produced this value AND the value is truthy.
     *
     * Both halves matter, and both are cross-language locks:
     *
     *  - "A real decision" means rule, holdout, bandit, sticky,
     *    datafileOverride, or runtimeOverride. A flag serving its default
     *    variation is NOT enabled however truthy that default is, and an
     *    uninitialised SDK is never enabled.
     *  - "Truthy" means JavaScript truthy, because the reference evaluator is
     *    the TypeScript engine. The string "0" is truthy here, unlike in PHP,
     *    and so is an empty array.
     */
    public function isEnabled(): bool
    {
        return $this->source->isDecision() && JsSemantics::isTruthy($this->value);
    }

    /**
     * True when the flag key is known to exist in the datafile.
     *
     * False for not_found and not_ready. A disabled flag exists: the customer
     * turned it off, which is not the same as it being absent. With no
     * datafile loaded, existence is unknowable, so the honest answer is false
     * rather than a guess.
     */
    public function exists(): bool
    {
        return !$this->source->isUnknownFlag();
    }

    /**
     * Build a Flag from the raw evaluator result array.
     *
     * @param array<string, mixed> $result
     * @internal Used by AvsbServer; not part of the public API contract.
     */
    public static function fromEvaluatorResult(array $result, int $durationMicros): self
    {
        $rawSource = $result['source'] ?? 'not_found';
        $source = is_string($rawSource)
            ? (EvaluationSource::tryFrom($rawSource) ?? EvaluationSource::NotFound)
            : EvaluationSource::NotFound;

        $rawRuleType = $result['ruleType'] ?? null;
        $ruleType = is_string($rawRuleType) ? RuleType::tryFrom($rawRuleType) : null;

        /** @var list<string> $reasons */
        $reasons = $result['reasons'] ?? [];

        return new self(
            value: $result['value'] ?? null,
            variationKey: $result['variationKey'] ?? null,
            source: $source,
            ruleId: $result['ruleId'] ?? null,
            ruleType: $ruleType,
            reasons: $reasons,
            evaluatedAt: (int) round(microtime(true) * 1000),
            durationMicros: $durationMicros,
        );
    }
}
