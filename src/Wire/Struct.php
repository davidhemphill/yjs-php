<?php

declare(strict_types=1);

namespace Yjs\Wire;

use Yjs\Binary\Encoder;
use Yjs\Id\Id;

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
}
