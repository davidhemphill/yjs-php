<?php

declare(strict_types=1);

namespace Hemp\Yjs\Wire\Content;

use Hemp\Yjs\Binary\Decoder;
use Hemp\Yjs\Binary\Encoder;

/**
 * Content ref 5 — an embedded object, carried as JSON text.
 *
 * Kept verbatim for the same reason as {@see Json}: re-serializing would not
 * reproduce JavaScript's bytes.
 */
final class Embed implements Content
{
    public function __construct(public readonly string $encoded) {}

    public static function read(Decoder $decoder): self
    {
        return new self($decoder->readVarString());
    }

    public function ref(): int
    {
        return 5;
    }

    public function length(): int
    {
        return 1;
    }

    public function write(Encoder $encoder): void
    {
        $encoder->writeVarString($this->encoded);
    }
}
