<?php

declare(strict_types=1);

namespace Hemp\Yjs\Exception;

use Hemp\Yjs\Binary\SafeInteger;

/**
 * A variable-length integer did not fit the JavaScript-safe integer range that
 * every Yjs client ID, clock, and length is required to stay inside.
 */
final class IntegerOutOfRange extends DecodeException
{
    public static function atPosition(int $position): self
    {
        return new self(sprintf(
            'Variable-length integer at offset %d exceeds the safe integer range (%d).',
            $position,
            SafeInteger::MAX,
        ));
    }

    public static function forValue(int|float $value): self
    {
        return new self(sprintf(
            'Value %s is outside the safe integer range (%d..%d).',
            var_export($value, true),
            SafeInteger::MIN,
            SafeInteger::MAX,
        ));
    }

    public static function negative(int|float $value): self
    {
        return new self(sprintf('Expected a non-negative integer, got %s.', var_export($value, true)));
    }
}
