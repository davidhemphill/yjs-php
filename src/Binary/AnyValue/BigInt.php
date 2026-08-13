<?php

declare(strict_types=1);

namespace Yjs\Binary\AnyValue;

/**
 * A JavaScript `bigint`, written by lib0 as a signed 64-bit big-endian integer.
 *
 * PHP integers are already 64-bit and signed, so the wrapper carries no extra
 * arithmetic — it exists to keep `bigint` distinguishable from `number`, which
 * lib0 tags differently and would otherwise decode to the same PHP int.
 */
final class BigInt
{
    public function __construct(public readonly int $value) {}

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }
}
