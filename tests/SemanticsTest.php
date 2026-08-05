<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Tests;

use Avsbhq\Avsb\AvsbServer;
use Avsbhq\Avsb\AvsbServerOptions;
use Avsbhq\Avsb\Core\EvaluationSource;
use Avsbhq\Avsb\Core\JsSemantics;
use Avsbhq\Avsb\Flag;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The semantics family of the conformance corpus, asserted directly.
 *
 * These rules cannot be expressed as a datafile plus a context, so they are
 * hand-authored in fixtures/semantics/manifest.json and every SDK asserts
 * against them: what isEnabled() and exists() answer, which source spellings
 * are legal, and what not_ready means.
 *
 * Reading the manifest rather than restating it is deliberate. A test that
 * copies the table can drift from it; this one fails the moment the contract
 * moves.
 */
final class SemanticsTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function manifest(): array
    {
        $candidates = [
            dirname(__DIR__, 2) . '/avsb-conformance/fixtures/semantics/manifest.json',
            __DIR__ . '/vendor/avsb-conformance/fixtures/semantics/manifest.json',
        ];
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                /** @var array<string, mixed> $decoded */
                $decoded = json_decode(
                    (string) file_get_contents($candidate),
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );

                return $decoded;
            }
        }

        throw new RuntimeException(
            "Semantics manifest not found. Looked in:\n  " . implode("\n  ", $candidates)
            . "\nIn the monorepo, run `npm run generate` in packages/avsb-conformance."
        );
    }

    private function flagWith(string $source, mixed $value): Flag
    {
        return new Flag(
            value: $value,
            variationKey: null,
            source: EvaluationSource::from($source),
            ruleId: null,
            ruleType: null,
            reasons: [],
            evaluatedAt: 0,
            durationMicros: 0,
        );
    }

    public function testEveryPublicSourceInTheManifestExists(): void
    {
        $manifest = self::manifest();
        $declared = array_map(
            static fn (EvaluationSource $case): string => $case->value,
            EvaluationSource::cases()
        );
        sort($declared);

        $expected = $manifest['sources']['public'];
        sort($expected);

        self::assertSame($expected, $declared);
    }

    public function testDecidedSourcesMatchTheManifest(): void
    {
        $manifest = self::manifest();
        $decided = array_map(
            static fn (EvaluationSource $case): string => $case->value,
            EvaluationSource::decided()
        );
        sort($decided);

        $expected = $manifest['sources']['decided'];
        sort($expected);

        self::assertSame($expected, $decided);
    }

    public function testNoBannedSpellingIsDeclared(): void
    {
        $manifest = self::manifest();
        foreach ($manifest['sources']['banned'] as $banned) {
            self::assertNull(
                EvaluationSource::tryFrom($banned),
                "Banned source spelling \"{$banned}\" must not be declared"
            );
        }
    }

    /**
     * PHP calls the string "0" false and an empty array false. JavaScript
     * calls both truthy, and JavaScript is the contract.
     */
    public function testTruthinessFollowsJavaScriptNotPhp(): void
    {
        $manifest = self::manifest();

        foreach ($manifest['truthiness']['falsy'] as $row) {
            self::assertFalse(
                JsSemantics::isTruthy($row['value']),
                json_encode($row['value']) . ' must be falsy: ' . $row['why']
            );
        }
        foreach ($manifest['truthiness']['truthy'] as $row) {
            self::assertTrue(
                JsSemantics::isTruthy($row['value']),
                json_encode($row['value']) . ' must be truthy: ' . $row['why']
            );
        }

        // Rows no JSON fixture can express.
        self::assertFalse(JsSemantics::isTruthy(NAN));
        self::assertFalse(JsSemantics::isTruthy(-0.0));
        self::assertTrue(JsSemantics::isTruthy('0'));
        self::assertTrue(JsSemantics::isTruthy([]));
    }

    public function testIsEnabledMatchesEveryManifestCase(): void
    {
        $manifest = self::manifest();

        foreach ($manifest['isEnabled']['cases'] as $case) {
            $flag = $this->flagWith($case['source'], $case['value']);
            self::assertSame(
                $case['expected'],
                $flag->isEnabled(),
                "{$case['case']}: source={$case['source']} value="
                . json_encode($case['value'])
            );
        }
    }

    public function testExistsMatchesEveryManifestCase(): void
    {
        $manifest = self::manifest();

        foreach ($manifest['exists']['cases'] as $case) {
            $flag = $this->flagWith($case['source'], true);
            self::assertSame($case['expected'], $flag->exists(), $case['case']);
        }
    }

    public function testNotReadyReturnsTheCallerDefaultWithTheLockedEnvelope(): void
    {
        $manifest = self::manifest();
        // No datafile, no key: nothing to look in.
        $server = new AvsbServer('', new AvsbServerOptions(events: false, heartbeat: false));

        $flag = $server->getNumberFlag('anything', 42.5);

        self::assertSame(42.5, $flag->value);
        self::assertSame($manifest['notReady']['expected']['source'], $flag->source->value);
        self::assertNull($flag->variationKey);
        self::assertNull($flag->ruleId);
        self::assertNull($flag->ruleType);
        self::assertFalse($flag->isEnabled());
        self::assertFalse($flag->exists());
    }

    public function testNotReadyReasonNamesTheStateRatherThanClaimingTheFlagIsMissing(): void
    {
        $server = new AvsbServer('', new AvsbServerOptions(events: false, heartbeat: false));

        $flag = $server->getBoolFlag('anything', false);

        self::assertCount(1, $flag->reasons);
        self::assertStringContainsStringIgnoringCase('not ready', $flag->reasons[0]);
        self::assertStringNotContainsStringIgnoringCase('not found', $flag->reasons[0]);
    }
}
