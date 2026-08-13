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

    public function write(Encoder $encoder): void
    {
        $encoder->writeUint8(StructInfo::GC_REF)->writeVarUint($this->length);
    }
}
