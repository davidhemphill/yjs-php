<?php

declare(strict_types=1);

namespace Yjs\Wire\Content;

use Yjs\Binary\Decoder;
use Yjs\Binary\Encoder;
use Yjs\Binary\Utf16;

/**
 * Content ref 4 — text, occupying one clock per UTF-16 code unit.
 *
 * This is the one content type whose length is neither a count of elements nor
 * a flat one, and the only one where PHP's natural measure is the wrong one. An
 * emoji is one PHP code point, four UTF-8 bytes, and two clocks.
 */
final class Text implements Content
{
    public function __construct(public readonly string $text) {}

    public static function read(Decoder $decoder): self
    {
        return new self($decoder->readVarString());
    }

    public function ref(): int
    {
        return 4;
    }

    public function length(): int
    {
        return Utf16::length($this->text);
    }

    public function write(Encoder $encoder): void
    {
        $encoder->writeVarString($this->text);
    }
}
