<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Tests;

use Avsbhq\Avsb\Core\Evaluator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The conformance allocation family: targeted delivery rollouts (N1-1).
 *
 * Six generated fixtures, one rollout rule each (5, 30 and 50 percent, a
 * fractional 0.12345 boundary, 100 percent and no allocation), each with 200
 * contexts whose outcomes the TypeScript reference recorded. Every one of the
 * 1,200 evaluations is replayed, reasons included, so a visitor is inside or
 * outside a rollout identically here and in every other SDK.
 *
 * FAIL-HARD RULE (conformance README, "The allocation family"): a missing
 * manifest, an empty or miscounted fixture list, a missing file, a case that
 * differs from its manifest entry, a fixture with the wrong number of
 * evaluations, or a JSON file the manifest does not name all throw. Fixtures
 * resolve exactly as ConformanceTest resolves them.
 */
final class ConformanceAllocationTest extends TestCase
{
    private const HINT = 'Run `npm run generate` then `npm run vendor` in packages/avsb-conformance.';

    private const FIELDS = ['value', 'variationKey', 'source', 'ruleId', 'ruleType', 'reasons'];

    private static function allocationDir(): string
    {
        return ConformanceTest::fixturesDir() . '/allocation';
    }

    /** @return array<string, mixed> */
    private static function manifest(): array
    {
        $dir = self::allocationDir();
        $manifest = ConformanceTest::readJsonFile($dir . '/manifest.json', 'allocation manifest');
        $fixtures = $manifest['fixtures'] ?? null;
        if (!is_array($fixtures) || $fixtures === []) {
            throw new RuntimeException(
                "The allocation manifest at {$dir} lists no fixtures. An empty family "
                . 'guarantees nothing. ' . self::HINT
            );
        }
        if (($manifest['fixtureCount'] ?? null) !== count($fixtures)) {
            throw new RuntimeException(
                'The allocation manifest says fixtureCount=' . json_encode($manifest['fixtureCount'] ?? null)
                . ' but lists ' . count($fixtures) . ' fixtures, so it was hand-edited. ' . self::HINT
            );
        }
        if (!is_int($manifest['contextsPerFixture'] ?? null) || $manifest['contextsPerFixture'] <= 0) {
            throw new RuntimeException('The allocation manifest declares no contextsPerFixture.');
        }

        return $manifest;
    }

    /**
     * @param array<string, mixed> $manifest
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private static function loadFixture(array $manifest, array $entry): array
    {
        $file = $entry['file'];
        $fixture = ConformanceTest::readJsonFile(self::allocationDir() . '/' . $file, "allocation fixture {$file}");
        if (($fixture['case'] ?? null) !== $entry['case']) {
            throw new RuntimeException(
                "Allocation fixture {$file} declares case " . json_encode($fixture['case'] ?? null)
                . " but the manifest names it \"{$entry['case']}\"."
            );
        }
        $evaluations = $fixture['evaluations'] ?? null;
        if (!is_array($evaluations) || count($evaluations) !== $manifest['contextsPerFixture']) {
            throw new RuntimeException(
                "Allocation fixture {$file} does not carry {$manifest['contextsPerFixture']} evaluations. " . self::HINT
            );
        }

        return $fixture;
    }

    /**
     * Replays every recorded evaluation of one fixture.
     *
     * @param array<string, mixed> $fixture
     * @return array{0: int, 1: list<string>} evaluations run, and each drift found
     */
    private static function replay(array $fixture): array
    {
        $ran = 0;
        $drift = [];
        foreach ($fixture['evaluations'] as $evaluation) {
            // Reasons are part of the contract, so the harness always collects them.
            $reasons = [];
            $result = Evaluator::evaluateFlag(
                $fixture['flagKey'],
                $fixture['defaultValue'],
                $evaluation['context'],
                $fixture['datafile'],
                $reasons
            );
            $ran++;
            foreach (self::FIELDS as $field) {
                if ($result[$field] !== $evaluation['expected'][$field]) {
                    $drift[] = $evaluation['context']['key'] . ": {$field} " . json_encode($result[$field])
                        . ', want ' . json_encode($evaluation['expected'][$field]);
                    break;
                }
            }
        }

        return [$ran, $drift];
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function allocationFixtureProvider(): array
    {
        // Throwing here is deliberate: PHPUnit reports it, never "no tests to run".
        $manifest = self::manifest();
        $rows = [];
        foreach ($manifest['fixtures'] as $entry) {
            $rows[$entry['case']] = [$entry];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $entry
     * @dataProvider allocationFixtureProvider
     */
    public function testEveryRecordedEvaluationMatchesTheReference(array $entry): void
    {
        $manifest = self::manifest();
        [$ran, $drift] = self::replay(self::loadFixture($manifest, $entry));

        self::assertSame($manifest['contextsPerFixture'], $ran, "{$entry['case']}: evaluations run");
        self::assertSame([], $drift, "{$entry['case']}: contexts that drifted from the reference");
    }

    public function testTheWholeFamilyRanSoNothingCanPassByRunningNothing(): void
    {
        $manifest = self::manifest();
        $total = 0;
        foreach ($manifest['fixtures'] as $entry) {
            [$ran] = self::replay(self::loadFixture($manifest, $entry));
            $total += $ran;
        }

        self::assertSame($manifest['fixtureCount'] * $manifest['contextsPerFixture'], $total);
        self::assertSame(1200, $total);
    }

    public function testTheFamilyCarriesTheSixRolloutLevels(): void
    {
        self::assertSame(
            [
                'targeted_delivery_allocation_0.05',
                'targeted_delivery_allocation_0.3',
                'targeted_delivery_allocation_0.5',
                'targeted_delivery_allocation_fractional_boundary',
                'targeted_delivery_allocation_1.0',
                'targeted_delivery_allocation_null',
            ],
            array_map(static fn (array $entry): string => $entry['case'], self::manifest()['fixtures'])
        );
    }

    public function testNoAllocationFixtureIsLeftOutOfTheManifest(): void
    {
        $named = [];
        foreach (self::manifest()['fixtures'] as $entry) {
            $named[$entry['file']] = true;
        }

        $orphans = [];
        foreach (glob(self::allocationDir() . '/*.json') ?: [] as $path) {
            $name = basename($path);
            if ($name !== 'manifest.json' && !isset($named[$name])) {
                $orphans[] = $name;
            }
        }

        self::assertSame([], $orphans, 'allocation fixtures not named by the manifest');
    }

    public function testTheFractionalFixturePutsItsSearchedBoundaryKeysFirst(): void
    {
        $manifest = self::manifest();
        $fractional = null;
        foreach ($manifest['fixtures'] as $entry) {
            if ($entry['case'] === 'targeted_delivery_allocation_fractional_boundary') {
                $fractional = self::loadFixture($manifest, $entry);
            }
        }
        self::assertNotNull($fractional, 'the fractional boundary fixture is missing');

        $probes = $fractional['probes'];
        self::assertSame([1234, 1235], array_column($probes, 'bucket'));
        $firstTwo = array_slice($fractional['evaluations'], 0, 2);
        self::assertSame(
            array_column($probes, 'resolvedKey'),
            array_map(static fn (array $e): string => $e['context']['key'], $firstTwo)
        );
        // Bucket 1234 is inside the 1234.5 threshold; a port that floors it would say no.
        self::assertSame(
            ['rule', 'default'],
            array_map(static fn (array $e): string => $e['expected']['source'], $firstTwo)
        );
    }
}
