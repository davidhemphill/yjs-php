<?php

declare(strict_types=1);

namespace Yjs\Binary\AnyValue;

/**
 * A JavaScript `Uint8Array` inside an "any" value.
 *
 * A PHP string is the natural carrier for both text and raw bytes, but the
 * wire keeps them apart. Wrapping the binary case is what lets a decoded
 * `Uint8Array` re-encode as one instead of as a string.
 */
final class Bytes
{
    public function __construct(public readonly string $bytes) {}

    public function length(): int
    {
        return strlen($this->bytes);
    }

    public function equals(self $other): bool
    {
        return $this->bytes === $other->bytes;
    }
}
