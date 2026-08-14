<?php

declare(strict_types=1);

namespace Hemp\Yjs\Wire\Content;

use Hemp\Yjs\Binary\Decoder;
use Hemp\Yjs\Binary\Encoder;
use Hemp\Yjs\Binary\Utf16;

/**
 * Content ref 4 — text, occupying one clock per UTF-16 code unit.
 *
 * This is the one content type whose length is neither a count of elements nor
 * a flat one, and the only one where PHP's natural measure is the wrong one. An
 * emoji is one PHP code point, four UTF-8 bytes, and two clocks.
 */
final class Text implements Sliceable
{
    /**
     * Split at a UTF-16 offset, the way Yjs splits string content.
     *
     * {@see Utf16::split()} carries the surrogate-pair handling: a boundary
     * inside a pair becomes U+FFFD on both sides, which damages the character
     * but leaves each half exactly as long as the clocks require.
     *
     * @return array{0: Content, 1: Content}
     */
    public function split(int $offset): array
    {
        [$left, $right] = Utf16::split($this->text, $offset);

        return [new self($left), new self($right)];
    }

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
