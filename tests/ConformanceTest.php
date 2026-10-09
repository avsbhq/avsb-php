<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Tests;

use Avsbhq\Avsb\Core\Evaluator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * ConformanceTest — loads the conformance corpus manifest and asserts each
 * case against the PHP evaluator.
 *
 * All cross-language SDKs MUST produce identical outputs for these cases.
 * Any divergence is a bucketing or evaluation bug.
 *
 * FAIL-HARD RULE (conformance README): a missing manifest, an empty manifest,
 * or a missing per-case file throws. The provider used to return a bogus
 * single row when the manifest was absent and `continue` past missing
 * per-case files, so a corpus that had not been vendored into the published
 * repo produced a green run over zero real cases.
 *
 * Fixture resolution prefers the monorepo copy and falls back to the copy
 * vendored into this package, which is what the published repo carries.
 * fixturesDir() and readJsonFile() are public so ConformanceAllocationTest
 * resolves and reads the allocation family through the same code.
 */
final class ConformanceTest extends TestCase
{
    /** @return string[] */
    private static function fixtureCandidates(): array
    {
        return [
            // Monorepo: packages/avsb-php/tests -> packages/avsb-conformance/fixtures
            dirname(__DIR__, 2) . '/avsb-conformance/fixtures',
            // Published repo: vendored copy shipped with this package.
            __DIR__ . '/vendor/avsb-conformance/fixtures',
        ];
    }

    public static function fixturesDir(): string
    {
        foreach (self::fixtureCandidates() as $candidate) {
            if (is_file($candidate . '/manifest.json')) {
                return $candidate;
            }
        }

        throw new RuntimeException(
            "Conformance fixtures not found. Looked for manifest.json in:\n  "
            . implode("\n  ", self::fixtureCandidates()) . "\n"
            . 'In the monorepo, run `npm run generate` in packages/avsb-conformance. '
            . 'For this repo standalone, run `npm run vendor` there and commit tests/vendor.'
        );
    }

    /** @return array<string, mixed> */
    public static function readJsonFile(string $path, string $what): array
    {
        if (!is_file($path)) {
            throw new RuntimeException(
                "Conformance fixture missing: {$path} ({$what}). "
                . 'Run `npm run generate` in packages/avsb-conformance.'
            );
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        return $decoded;
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function conformanceCaseProvider(): array
    {
        $fixturesDir = self::fixturesDir();
        $manifest    = self::readJsonFile($fixturesDir . '/manifest.json', 'manifest');

        if (!isset($manifest['cases']) || !is_array($manifest['cases']) || $manifest['cases'] === []) {
            throw new RuntimeException(
                "Conformance manifest at {$fixturesDir} lists no cases. "
                . 'An empty corpus guarantees nothing.'
            );
        }
        if (isset($manifest['caseCount']) && $manifest['caseCount'] !== count($manifest['cases'])) {
            throw new RuntimeException(
                'Conformance manifest says caseCount=' . $manifest['caseCount']
                . ' but carries ' . count($manifest['cases']) . ' cases, so it was hand-edited.'
            );
        }

        $cases = [];
        foreach ($manifest['cases'] as $entry) {
            $expected = self::readJsonFile(
                $fixturesDir . '/' . $entry['expected'],
                "manifest case {$entry['case']}"
            );
            if (($expected['case'] ?? null) !== $entry['case']) {
                throw new RuntimeException(
                    "Fixture {$entry['expected']} declares a different case id than the manifest."
                );
            }
            $cases[$entry['case']] = [$expected, $fixturesDir];
        }

        return $cases;
    }

    public function testManifestIsNotEmpty(): void
    {
        $fixturesDir = self::fixturesDir();
        $manifest    = self::readJsonFile($fixturesDir . '/manifest.json', 'manifest');
        self::assertNotEmpty($manifest['cases']);
        self::assertSame(count($manifest['cases']), count(self::conformanceCaseProvider()));
    }

    /**
     * Fixture files the manifest does not name are renamed or deleted cases
     * whose output lingers: they replay green against the engine that produced
     * them while locking semantics nobody declares.
     */
    public function testNoOrphanFixtures(): void
    {
        $fixturesDir = self::fixturesDir();
        $manifest    = self::readJsonFile($fixturesDir . '/manifest.json', 'manifest');

        $named = [];
        foreach ($manifest['cases'] as $entry) {
            $named[$entry['case'] . '.json'] = true;
        }

        $orphans = [];
        foreach (glob($fixturesDir . '/expected/*.json') ?: [] as $path) {
            $name = basename($path);
            if (!isset($named[$name])) {
                $orphans[] = $name;
            }
        }

        self::assertSame([], $orphans, 'fixtures not named by the manifest');
    }

    /**
     * @param array<string, mixed> $expected
     * @dataProvider conformanceCaseProvider
     */
    public function testConformanceCase(array $expected, string $fixturesDir): void
    {
        $caseName = $expected['case'];

        $datafile = self::readJsonFile(
            $fixturesDir . '/' . $expected['datafile'],
            "datafile for {$caseName}"
        );
        $context = self::readJsonFile(
            $fixturesDir . '/' . $expected['context'],
            "context for {$caseName}"
        );

        $flagKey      = $expected['flagKey'];
        $defaultValue = $expected['defaultValue'];
        $exp          = $expected['expected'];

        // Reasons are part of the corpus, so the harness always collects them.
        $reasons = [];
        $result  = Evaluator::evaluateFlag($flagKey, $defaultValue, $context, $datafile, $reasons);

        // Every field is asserted unconditionally. A fixture missing one is a
        // malformed fixture, not a licence to skip the check.
        self::assertSame(
            $exp['value'],
            $result['value'],
            "Case '{$caseName}': value mismatch. Got: " . json_encode($result['value'])
        );
        self::assertSame(
            $exp['variationKey'],
            $result['variationKey'],
            "Case '{$caseName}': variationKey mismatch"
        );
        self::assertSame(
            $exp['source'],
            $result['source'],
            "Case '{$caseName}': source mismatch"
        );
        self::assertSame(
            $exp['ruleId'],
            $result['ruleId'],
            "Case '{$caseName}': ruleId mismatch"
        );
        self::assertSame(
            $exp['ruleType'],
            $result['ruleType'],
            "Case '{$caseName}': ruleType mismatch"
        );
        // Exact, ordered comparison of the decision trail (spec section 1.4).
        self::assertSame(
            $exp['reasons'],
            $result['reasons'],
            "Case '{$caseName}': reasons mismatch"
        );
    }
}
