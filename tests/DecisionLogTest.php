<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Tests;

use Avsbhq\Avsb\Core\EvaluationSource;
use Avsbhq\Avsb\Core\RuleType;
use Avsbhq\Avsb\DecisionLog;
use Avsbhq\Avsb\DecisionLogEntry;
use Avsbhq\Avsb\Flag;
use PHPUnit\Framework\TestCase;

final class DecisionLogTest extends TestCase
{
    private function makeFlag(mixed $value = true, string $source = 'rule'): Flag
    {
        $evalSource = match ($source) {
            'rule'      => EvaluationSource::Rule,
            'disabled'  => EvaluationSource::Disabled,
            'not_found' => EvaluationSource::NotFound,
            default     => EvaluationSource::Default,
        };
        return new Flag(
            value:          $value,
            variationKey:   'on',
            source:         $evalSource,
            ruleId:         'rule_1',
            ruleType:       RuleType::TargetedDelivery,
            reasons:        ['matched rule rule_1'],
            evaluatedAt:    1716000000000,
            durationMicros: 42,
        );
    }

    public function testAppendAndRetrieveEntry(): void
    {
        $log   = new DecisionLog();
        $entry = DecisionLogEntry::fromFlag(
            $this->makeFlag(),
            'my-flag',
            [['kind' => 'user', 'key' => 'alice']]
        );
        $log->append($entry);
        self::assertCount(1, $log->getEntries());
        self::assertSame('my-flag', $log->getEntries()[0]->flagKey);
    }

    public function testClearRemovesAllEntries(): void
    {
        $log = new DecisionLog();
        $log->append(DecisionLogEntry::fromFlag($this->makeFlag(), 'f1', []));
        $log->append(DecisionLogEntry::fromFlag($this->makeFlag(), 'f2', []));
        $log->clear();
        self::assertCount(0, $log->getEntries());
    }

    public function testRedactionReplacesPrivateAttributeValueInStringFlag(): void
    {
        $flag = $this->makeFlag('alice@example.com');
        $entry = DecisionLogEntry::fromFlag(
            $flag,
            'my-flag',
            [['kind' => 'user', 'key' => 'alice']],
            ['alice@example.com']
        );
        // The value references a private attribute value — should be redacted.
        self::assertSame('[REDACTED]', $entry->value);
    }

    public function testNonStringValueIsNotRedacted(): void
    {
        $flag  = $this->makeFlag(true);
        $entry = DecisionLogEntry::fromFlag(
            $flag,
            'my-flag',
            [],
            ['some-private-attr']
        );
        // Boolean values are not string-scanned — no change.
        self::assertTrue($entry->value);
    }

    public function testReasonsAreRedactedWhenContainingPrivateAttrValue(): void
    {
        // Create a flag whose reasons mention a private attribute value.
        $flag = new Flag(
            value:          true,
            variationKey:   'on',
            source:         EvaluationSource::Rule,
            ruleId:         'rule_1',
            ruleType:       RuleType::TargetedDelivery,
            reasons:        ['matched because plan=secret_plan_value'],
            evaluatedAt:    0,
            durationMicros: 0,
        );
        $entry = DecisionLogEntry::fromFlag(
            $flag,
            'my-flag',
            [],
            ['secret_plan_value']
        );
        self::assertStringContainsString('[REDACTED]', $entry->reasons[0]);
        self::assertStringNotContainsString('secret_plan_value', $entry->reasons[0]);
    }

    public function testManuallyFiredDefaultsToFalse(): void
    {
        $entry = DecisionLogEntry::fromFlag($this->makeFlag(), 'f', []);
        self::assertFalse($entry->manuallyFired);
    }

    public function testManuallyFiredCanBeSetToTrue(): void
    {
        $entry = DecisionLogEntry::fromFlag($this->makeFlag(), 'f', [], [], true);
        self::assertTrue($entry->manuallyFired);
    }
}
