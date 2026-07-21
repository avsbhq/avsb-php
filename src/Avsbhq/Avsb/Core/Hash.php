<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Core;

/**
 * MurmurHash3_x86_32: deterministic 32-bit hash for visitor bucketing.
 *
 * Direct port of hash.ts from @avsbhq/core. All arithmetic uses explicit
 * 32-bit masking (& 0xFFFFFFFF) to match JavaScript's 32-bit integer
 * semantics on 64-bit PHP. Multiply uses bcmul-free bit-manipulation so
 * no bcmath or GMP extension is required.
 */
final class Hash
{
    private const C1 = 0xcc9e2d51;
    private const C2 = 0x1b873593;

    /**
     * Compute MurmurHash3_x86_32 for $key with $seed.
     *
     * Returns an unsigned 32-bit integer in the range [0, 2^32 - 1].
     */
    public static function murmurhash3(string $key, int $seed = 0): int
    {
        $h1    = $seed & 0xFFFFFFFF;
        $len   = strlen($key);
        $nblocks = (int) ($len / 4);

        // Body — process 4-byte blocks.
        for ($i = 0; $i < $nblocks; $i++) {
            $i4 = $i * 4;
            $k1 = (ord($key[$i4])       & 0xff)
                | ((ord($key[$i4 + 1])  & 0xff) << 8)
                | ((ord($key[$i4 + 2])  & 0xff) << 16)
                | ((ord($key[$i4 + 3])  & 0xff) << 24);

            $k1 = self::imul32($k1, self::C1);
            $k1 = self::rotl32($k1, 15);
            $k1 = self::imul32($k1, self::C2);

            $h1 ^= $k1;
            $h1  = self::rotl32($h1, 13);
            $h1  = (self::imul32($h1, 5) + 0xe6546b64) & 0xFFFFFFFF;
        }

        // Tail — remaining 1-3 bytes.
        $tail = $nblocks * 4;
        $k1   = 0;

        switch ($len & 3) {
            case 3:
                $k1 ^= (ord($key[$tail + 2]) & 0xff) << 16;
                // fall through
            case 2:
                $k1 ^= (ord($key[$tail + 1]) & 0xff) << 8;
                // fall through
            case 1:
                $k1 ^= ord($key[$tail]) & 0xff;
                $k1  = self::imul32($k1, self::C1);
                $k1  = self::rotl32($k1, 15);
                $k1  = self::imul32($k1, self::C2);
                $h1 ^= $k1;
        }

        // Finalization mix — force all bits to avalanche.
        $h1 ^= $len;
        $h1  = self::fmix32($h1);

        return $h1 & 0xFFFFFFFF;
    }

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    /**
     * 32-bit left-rotation — mirrors `(v << r) | (v >>> (32 - r))` in JS.
     */
    private static function rotl32(int $v, int $r): int
    {
        $v &= 0xFFFFFFFF;
        return (($v << $r) | ($v >> (32 - $r))) & 0xFFFFFFFF;
    }

    /**
     * 32-bit integer multiplication — mirrors Math.imul().
     *
     * PHP integers are 64-bit on modern platforms, so we perform the
     * multiplication in 64-bit space and then mask to 32 bits to match
     * the wrapping semantics of Math.imul().
     */
    private static function imul32(int $a, int $b): int
    {
        // Mask both operands to their signed 32-bit range first, then
        // multiply and mask the result. This replicates JS Math.imul exactly.
        $a = $a & 0xFFFFFFFF;
        $b = $b & 0xFFFFFFFF;

        // Lower 16 bits × full second operand, upper 16 bits × lower 16 bits.
        $lo = ($a & 0xffff) * $b;
        $hi = ($a >> 16) * ($b & 0xffff);

        return (($lo + ($hi << 16)) & 0xFFFFFFFF);
    }

    /**
     * Finalization mix — fmix32 from MurmurHash3.
     */
    private static function fmix32(int $h): int
    {
        $h &= 0xFFFFFFFF;
        $h ^= ($h >> 16) & 0xFFFFFFFF;
        $h  = self::imul32($h, 0x85ebca6b);
        $h ^= ($h >> 13) & 0xFFFFFFFF;
        $h  = self::imul32($h, 0xc2b2ae35);
        $h ^= ($h >> 16) & 0xFFFFFFFF;
        return $h & 0xFFFFFFFF;
    }
}
