<?php

declare(strict_types=1);

namespace Yjs\Wire;

use Yjs\Binary\Encoder;
use Yjs\Binary\SafeInteger;
use Yjs\Id\Id;

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
}
