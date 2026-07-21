<?php

declare(strict_types=1);

namespace Avsbhq\Avsb;

use Avsbhq\Avsb\Core\EvaluationSource;
use Avsbhq\Avsb\Core\RuleType;

/**
 * DecisionLogEntry: readonly value object for a single flag decision.
 *
 * Mirrors §2.5 DecisionLogEntry from types.ts. Private attributes are
 * redacted in the value and reasons fields per the spec.
 */
readonly class DecisionLogEntry
{
    /**
     * @param string              $flagKey
     * @param mixed               $value          Redacted if private attrs appeared.
     * @param string|null         $variationKey
     * @param EvaluationSource    $source
     * @param string|null         $ruleId
     * @param RuleType|null       $ruleType
     * @param string[]            $reasons        Private attr values replaced with '[REDACTED]'.
     * @param int                 $evaluatedAt    ms-epoch.
     * @param int                 $durationMicros µs elapsed.
     * @param array<array{kind: string, key: string}> $contextSummary
     * @param bool                $manuallyFired
     */
    public function __construct(
        public string $flagKey,
        public mixed $value,
        public ?string $variationKey,
        public EvaluationSource $source,
        public ?string $ruleId,
        public ?RuleType $ruleType,
        public array $reasons,
        public int $evaluatedAt,
        public int $durationMicros,
        public array $contextSummary,
        public bool $manuallyFired = false,
    ) {}

    /**
     * Build from a Flag value object + context summary, applying redaction.
     *
     * @param Flag          $flag
     * @param string        $flagKey
     * @param array         $contextSummary  From Context::contextSummary().
     * @param string[]      $privateAttributes  Keys to redact.
     * @param bool          $manuallyFired
     */
    public static function fromFlag(
        Flag $flag,
        string $flagKey,
        array $contextSummary,
        array $privateAttributes = [],
        bool $manuallyFired = false,
    ): self {
        $value   = $flag->value;
        $reasons = $flag->reasons;

        if (!empty($privateAttributes)) {
            $value   = self::redactValue($value, $privateAttributes);
            $reasons = self::redactReasons($reasons, $privateAttributes);
        }

        return new self(
            flagKey:        $flagKey,
            value:          $value,
            variationKey:   $flag->variationKey,
            source:         $flag->source,
            ruleId:         $flag->ruleId,
            ruleType:       $flag->ruleType,
            reasons:        $reasons,
            evaluatedAt:    $flag->evaluatedAt,
            durationMicros: $flag->durationMicros,
            contextSummary: $contextSummary,
            manuallyFired:  $manuallyFired,
        );
    }

    // ------------------------------------------------------------------
    // Redaction helpers
    // ------------------------------------------------------------------

    private static function redactValue(mixed $value, array $privateAttributes): mixed
    {
        if (!is_string($value)) {
            return $value;
        }
        foreach ($privateAttributes as $attr) {
            if (str_contains($value, $attr)) {
                return '[REDACTED]';
            }
        }
        return $value;
    }

    /** @param string[] $reasons */
    private static function redactReasons(array $reasons, array $privateAttributes): array
    {
        return array_map(
            static function (string $reason) use ($privateAttributes): string {
                foreach ($privateAttributes as $attr) {
                    $reason = str_replace($attr, '[REDACTED]', $reason);
                }
                return $reason;
            },
            $reasons
        );
    }
}

/**
 * DecisionLog: collects DecisionLogEntry values for the lifetime of a
 * request or server instance. Entries are append-only.
 */
final class DecisionLog
{
    /** @var DecisionLogEntry[] */
    private array $entries = [];

    public function append(DecisionLogEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    /** @return DecisionLogEntry[] */
    public function getEntries(): array
    {
        return $this->entries;
    }

    public function clear(): void
    {
        $this->entries = [];
    }
}
