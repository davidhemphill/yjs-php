<?php

declare(strict_types=1);

namespace Hemp\Yjs\Binary;

use Hemp\Yjs\Exception\IntegerOutOfRange;

/**
 * Every client ID, clock, length, and count on the Yjs wire originates in a
 * JavaScript `Number`. PHP integers are 64-bit and can hold values JavaScript
 * would silently round, so the boundary has to be enforced explicitly rather
 * than inherited from the platform.
 */
final class SafeInteger
{
    /**
     * Number.MAX_SAFE_INTEGER — 2^53 - 1.
     */
    public const int MAX = 9007199254740991;

    /**
     * Number.MIN_SAFE_INTEGER — -(2^53 - 1).
     */
    public const int MIN = -9007199254740991;

    /**
     * The widest a lib0 variable-length integer can be while still decoding to
     * a safe integer: 8 groups of 7 bits covers all 53 significant bits.
     *
     * lib0 itself has no byte cap and relies on its running magnitude check.
     * Capping the byte count as well makes a padded varint cheap to reject and
     * keeps the reader's arithmetic inside PHP's integer range.
     */
    public const int MAX_VARINT_BYTES = 8;

    private function __construct() {}

    public static function isSafe(int|float $value): bool
    {
        if (is_float($value)) {
            return is_finite($value)
                && floor($value) === $value
                && $value >= self::MIN
                && $value <= self::MAX;
        }

        return $value >= self::MIN && $value <= self::MAX;
    }

    /**
     * @throws IntegerOutOfRange
     */
    public static function assert(int|float $value): int
    {
        if (! self::isSafe($value)) {
            throw IntegerOutOfRange::forValue($value);
        }

        return (int) $value;
    }

    /**
     * Client IDs, clocks, lengths, and counts are all non-negative.
     *
     * @throws IntegerOutOfRange
     */
    public static function assertNonNegative(int|float $value): int
    {
        $safe = self::assert($value);

        if ($safe < 0) {
            throw IntegerOutOfRange::negative($value);
        }

        return $safe;
    }
}
