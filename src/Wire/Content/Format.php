<?php

declare(strict_types=1);

namespace Yjs\Wire\Content;

use Yjs\Binary\Decoder;
use Yjs\Binary\Encoder;

/**
 * Content ref 6 — a rich-text formatting mark: a key and a JSON value.
 *
 * The value is kept verbatim, as with {@see Json} and {@see Embed}.
 */
final class Format implements Content
{
    public function __construct(
        public readonly string $key,
        public readonly string $encodedValue,
    ) {}

    public static function read(Decoder $decoder): self
    {
        return new self($decoder->readVarString(), $decoder->readVarString());
    }

    public function ref(): int
    {
        return 6;
    }

    public function length(): int
    {
        return 1;
    }

    public function write(Encoder $encoder): void
    {
        $encoder->writeVarString($this->key)->writeVarString($this->encodedValue);
    }
}
