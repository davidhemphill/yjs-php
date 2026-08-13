<?php

declare(strict_types=1);

namespace Yjs\Wire\Content;

use Yjs\Binary\Decoder;
use Yjs\Binary\Encoder;

/**
 * Content ref 3 — an opaque byte array, occupying one clock however large it is.
 */
final class Binary implements Content
{
    public function __construct(public readonly string $bytes) {}

    public static function read(Decoder $decoder): self
    {
        return new self($decoder->readVarBytes());
    }

    public function ref(): int
    {
        return 3;
    }

    public function length(): int
    {
        return 1;
    }

    public function write(Encoder $encoder): void
    {
        $encoder->writeVarBytes($this->bytes);
    }
}
