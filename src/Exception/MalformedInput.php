<?php

declare(strict_types=1);

namespace Yjs\Exception;

/**
 * The bytes were structurally readable but did not describe anything valid:
 * an unknown type tag, an invalid UTF-8 sequence, trailing data, and so on.
 */
final class MalformedInput extends DecodeException
{
    public static function unknownAnyTag(int $tag, int $position): self
    {
        return new self(sprintf('Unknown lib0 "any" type tag %d at offset %d.', $tag, $position));
    }

    public static function invalidUtf8(int $position): self
    {
        return new self(sprintf('Invalid UTF-8 sequence in string at offset %d.', $position));
    }

    public static function trailingBytes(int $remaining, int $position): self
    {
        return new self(sprintf('Expected end of input at offset %d, but %d byte(s) remain.', $position, $remaining));
    }
}
