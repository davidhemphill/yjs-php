<?php

declare(strict_types=1);

namespace Yjs\Exception;

/**
 * The decoder needed more bytes than the input contained.
 */
final class UnexpectedEndOfInput extends DecodeException
{
    public static function needing(int $bytes, int $available, int $position): self
    {
        return new self(sprintf(
            'Unexpected end of input: needed %d byte(s) at offset %d but only %d remain.',
            $bytes,
            $position,
            $available,
        ));
    }
}
