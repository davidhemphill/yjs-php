<?php

declare(strict_types=1);

namespace Hemp\Yjs\Wire;

use Hemp\Yjs\Binary\Encoder;
use Hemp\Yjs\Id\Id;

/**
 * One record in a client's struct section.
 *
 * There are exactly three: an {@see Item} carrying content, a {@see Gc} marking
 * collected space, and a {@see Skip} marking a gap the sender did not have.
 * Which one it is comes from the low five bits of the info byte.
 */
interface Struct
{
    public function id(): Id;

    /**
     * How many clocks this struct occupies. The next struct in the section
     * starts exactly this far along.
     */
    public function length(): int;

    /**
     * Write the info byte and everything that follows it.
     */
    public function write(Encoder $encoder): void;

    /**
     * Drop the first `$offset` clocks and return what remains.
     *
     * Merging two updates that overlap, and diffing an update against a state
     * vector that lands mid-struct, both need to keep only the tail of a
     * struct. Yjs writes that tail by passing an offset all the way down to the
     * content writer; producing the sliced struct up front is equivalent and
     * keeps the offset from having to be threaded through everything.
     */
    public function sliceFrom(int $offset): self;

    /**
     * The struct as Yjs would rewrite it.
     *
     * Yjs derives an Item's info byte from its fields every time it writes one,
     * so any operation that rebuilds an update — merging, diffing — normalizes
     * that byte on the way out. Decoding and re-encoding does not, which is why
     * this is a separate step rather than something the writer always does: an
     * update that is only being relayed must come out exactly as it went in.
     *
     * The bit this actually moves is `parentSub`. Yjs sets it from the value but
     * writes the field only for an Item with no origin, so an Item that has one
     * can arrive carrying a bit whose field was never on the wire. Preserved on
     * a round trip, dropped on a rewrite.
     */
    public function normalized(): self;
}
