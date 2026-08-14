<?php

declare(strict_types=1);

namespace Yjs\Wire\Content;

use Yjs\Binary\Decoder;
use Yjs\Binary\Encoder;
use Yjs\Binary\SafeInteger;

/**
 * Content ref 1 — a run of deleted clocks that kept its span but lost its
 * payload.
 */
final class Deleted implements Sliceable
{
    /**
     * @return array{0: Content, 1: Content}
     */
    public function split(int $offset): array
    {
        return [new self($offset), new self($this->length - $offset)];
    }

    public function __construct(public readonly int $length)
    {
        SafeInteger::assertNonNegative($length);
    }

    public static function read(Decoder $decoder): self
    {
        return new self($decoder->readVarUint());
    }

    public function ref(): int
    {
        return 1;
    }

    public function length(): int
    {
        return $this->length;
    }

    public function write(Encoder $encoder): void
    {
        $encoder->writeVarUint($this->length);
    }
}
