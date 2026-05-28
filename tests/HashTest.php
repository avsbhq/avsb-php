<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Tests;

use Avsbhq\Avsb\Core\Hash;
use PHPUnit\Framework\TestCase;

/**
 * HashTest — MurmurHash3_x86_32 parity with the TypeScript reference.
 *
 * Reference vectors are computed from the canonical TS implementation in
 * packages/avsb-core/src/hash.ts. The PHP port must produce identical
 * unsigned 32-bit values for the same inputs so the bucketing logic
 * produces the same traffic splits across languages.
 */
final class HashTest extends TestCase
{
    /**
     * Reference vectors: [key, seed, expectedHash]
     * Computed against the TypeScript murmurhash3 implementation in
     * packages/avsb-core/src/hash.ts — these are the canonical values
     * every per-language SDK port MUST produce.
     */
    public static function referenceVectorProvider(): array
    {
        return [
            'empty string seed 0'           => ['', 0, 0],
            'alice seed 0'                  => ['alice', 0, 1280413405],
            'alicecheckout-v2-experiment'   => ['alicecheckout-v2-experiment', 0, 3482744336],
            'hello seed 0'                  => ['hello', 0, 613153351],
            'hello seed 42'                 => ['hello', 42, 3806057185],
            'four-byte block exactfit'      => ['abcd', 0, 1139631978],
            'tail-2-bytes'                  => ['ab', 0, 2613040991],
            'tail-1-byte'                   => ['a', 0, 1009084850],
            'longer string seed 0'          => ['the quick brown fox', 0, 519850996],
            'known alloc hash alice+flag'   => ['alicecheckout-v2-experiment__rule_ab_split', 0, 689116954],
        ];
    }

    /** @dataProvider referenceVectorProvider */
    public function testReferenceVectors(string $key, int $seed, int $expected): void
    {
        $actual = Hash::murmurhash3($key, $seed);
        self::assertSame(
            $expected,
            $actual,
            "Hash mismatch for key=\"{$key}\" seed={$seed}: expected {$expected}, got {$actual}"
        );
    }

    public function testReturnIsAlwaysUnsigned32Bit(): void
    {
        // Result must never be negative and must fit in 32 bits.
        $result = Hash::murmurhash3('test-key', 0);
        self::assertGreaterThanOrEqual(0, $result);
        self::assertLessThanOrEqual(0xFFFFFFFF, $result);
    }

    public function testDeterministicForSameInput(): void
    {
        $first  = Hash::murmurhash3('deterministic', 7);
        $second = Hash::murmurhash3('deterministic', 7);
        self::assertSame($first, $second);
    }

    public function testDifferentSeedsProduceDifferentHashes(): void
    {
        $h0 = Hash::murmurhash3('seed-test', 0);
        $h1 = Hash::murmurhash3('seed-test', 1);
        self::assertNotSame($h0, $h1);
    }

    public function testModuloDistributionFor10000BucketRange(): void
    {
        // The bucketing math relies on % 10000. Verify a known user falls
        // in the expected bucket range matching the TS conformance fixture.
        // alice + 'checkout-v2-experiment' → hash % 10000 = 4336 per fixture.
        $hash   = Hash::murmurhash3('alicecheckout-v2-experiment') % 10000;
        self::assertSame(4336, $hash);
    }
}
