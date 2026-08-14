<?php

declare(strict_types=1);

namespace Yjs\Wire;

use Yjs\Binary\Encoder;
use Yjs\Binary\SafeInteger;
use Yjs\Id\Id;

/**
 * Space whose content has been garbage collected: the clocks are still spoken
 * for, but nothing remains to say what was there.
 */
final class Gc implements Struct
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

    /**
     * Absorb an adjacent run of collected space.
     *
     * Unlike an Item, a GC carries nothing that could disagree with its
     * neighbour, so two touching GCs are always one.
     */
    public function extendedBy(int $length): self
    {
        return new self($this->id, $this->length + $length);
    }

    public function write(Encoder $encoder): void
    {
        $encoder->writeUint8(StructInfo::GC_REF)->writeVarUint($this->length);
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
}
