<?php

declare(strict_types=1);

namespace Hemp\Yjs\Wire;

use Hemp\Yjs\Binary\Encoder;
use Hemp\Yjs\Binary\SafeInteger;
use Hemp\Yjs\Id\Id;

/**
 * A gap the sender could not fill.
 *
 * Skips appear when an update is built from a state vector the sender cannot
 * fully satisfy. They carry no content and apply to nothing; they exist so the
 * clocks after them still land in the right place.
 */
final class Skip implements Struct
{
    public function __construct(
        public readonly Id $id,
        public readonly int $length,
    ) {
        SafeInteger::assertNonNegative($length);
    }

    public function id(): Id
    {
        return $this->id;
    }

    public function length(): int
    {
        return $this->length;
    }

    public function write(Encoder $encoder): void
    {
        $encoder->writeUint8(StructInfo::SKIP_REF)->writeVarUint($this->length);
    }

    /**
     * Nothing to normalize: only an Item derives its info byte from fields.
     */
    public function normalized(): Struct
    {
        return $this;
    }

    public function sliceFrom(int $offset): Struct
    {
        return new self($this->id->advanced($offset), $this->length - $offset);
    }

    /**
     * Extend this skip to cover a further run of clocks.
     *
     * Two adjacent gaps are one gap. Yjs coalesces them while merging, and a
     * skip that stayed split would encode differently for the same meaning.
     */
    public function extendedBy(int $length): self
    {
        return new self($this->id, $this->length + $length);
    }
}
