<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Tests;

use Avsbhq\Avsb\Core\Evaluator;
use PHPUnit\Framework\TestCase;

/**
 * ConformanceTest — loads packages/avsb-conformance/fixtures/manifest.json
 * and asserts each case against the PHP evaluator.
 *
 * All cross-language SDKs MUST produce identical outputs for these cases.
 * Any divergence is a bucketing or evaluation bug.
 */
final class ConformanceTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        // Resolve fixtures path relative to this test file.
        // tests/ → packages/avsb-php/ → packages/ → packages/avsb-conformance/fixtures/
        $this->fixturesDir = dirname(__DIR__, 2) . '/avsb-conformance/fixtures';
    }

    public static function conformanceCaseProvider(): array
    {
        $fixturesDir = dirname(__DIR__, 2) . '/avsb-conformance/fixtures';
        $manifestPath = $fixturesDir . '/manifest.json';

        if (!file_exists($manifestPath)) {
            return [['case_not_found' => []]];
        }

        $manifest = json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $cases    = [];
        foreach ($manifest['cases'] as $entry) {
            $expectedPath = $fixturesDir . '/' . $entry['expected'];
            if (!file_exists($expectedPath)) {
                continue;
            }
            $expected = json_decode(file_get_contents($expectedPath), true, 512, JSON_THROW_ON_ERROR);
            $cases[$entry['case']] = [$expected, $fixturesDir];
        }
        return $cases;
    }

    /**
     * @dataProvider conformanceCaseProvider
     */
    public function testConformanceCase(array $expected, string $fixturesDir): void
    {
        // Load datafile.
        $datafilePath = $fixturesDir . '/' . $expected['datafile'];
        self::assertFileExists($datafilePath, "Datafile not found: {$datafilePath}");
        $datafile = json_decode(file_get_contents($datafilePath), true, 512, JSON_THROW_ON_ERROR);

        // Load context.
        $contextPath = $fixturesDir . '/' . $expected['context'];
        self::assertFileExists($contextPath, "Context not found: {$contextPath}");
        $context = json_decode(file_get_contents($contextPath), true, 512, JSON_THROW_ON_ERROR);

        $flagKey      = $expected['flagKey'];
        $defaultValue = $expected['defaultValue'];
        $exp          = $expected['expected'];

        $reasons = [];
        $result  = Evaluator::evaluateFlag($flagKey, $defaultValue, $context, $datafile, $reasons);

        $caseName = $expected['case'];

        // Assert required fields.
        self::assertSame(
            $exp['value'],
            $result['value'],
            "Case '{$caseName}': value mismatch. Got: " . json_encode($result['value'])
        );
        self::assertSame(
            $exp['variationKey'] ?? null,
            $result['variationKey'],
            "Case '{$caseName}': variationKey mismatch"
        );

        // source is present in most fixtures — skip assertion when absent
        // (some fixtures intentionally omit it to be forward-compatible).
        if (array_key_exists('source', $exp)) {
            self::assertSame(
                $exp['source'],
                $result['source'],
                "Case '{$caseName}': source mismatch"
            );
        }

        self::assertSame(
            $exp['ruleId'] ?? null,
            $result['ruleId'],
            "Case '{$caseName}': ruleId mismatch"
        );
        self::assertSame(
            $exp['ruleType'] ?? null,
            $result['ruleType'],
            "Case '{$caseName}': ruleType mismatch"
        );

        // Reasons are compared when present in the fixture.
        if (isset($exp['reasons'])) {
            self::assertSame(
                $exp['reasons'],
                $result['reasons'],
                "Case '{$caseName}': reasons mismatch"
            );
        }
    }
}
