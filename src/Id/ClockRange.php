<?php

declare(strict_types=1);

namespace Hemp\Yjs\Id;

use Hemp\Yjs\Binary\SafeInteger;
use Hemp\Yjs\Exception\EncodeException;

/**
 * A half-open run of clocks belonging to one client: `[clock, clock + length)`.
 *
 * Deletions are expressed as ranges and must stay that way. A document that has
 * had a million characters deleted carries a handful of ranges; expanding those
 * into one object per clock would turn a small delete set into hundreds of
 * megabytes, which is the difference between a server that stays up and one
 * that does not.
 */
final class ClockRange
{
    public function __construct(
        public readonly int $clock,
        public readonly int $length,
    ) {
        SafeInteger::assertNonNegative($clock);

        if ($length < 0) {
            throw new EncodeException(sprintf('A clock range cannot have a negative length, got %d.', $length));
        }

        SafeInteger::assertNonNegative($clock + $length);
    }

    /**
     * One past the last clock in the range.
     */
    public function end(): int
    {
        return $this->clock + $this->length;
    }

    public function isEmpty(): bool
    {
        return $this->length === 0;
    }

    public function contains(int $clock): bool
    {
        return $clock >= $this->clock && $clock < $this->end();
    }

    public function containsRange(self $other): bool
    {
        return $other->isEmpty() || ($other->clock >= $this->clock && $other->end() <= $this->end());
    }

    public function overlaps(self $other): bool
    {
        return $this->clock < $other->end() && $other->clock < $this->end();
    }

    /**
     * Whether the two ranges touch, so that their union is still one range.
     *
     * Adjacency counts as well as overlap: `[0,5)` and `[5,9)` describe the same
     * deleted run as `[0,9)`, and leaving them apart would make two delete sets
     * that mean the same thing compare unequal.
     */
    public function touches(self $other): bool
    {
        return $this->clock <= $other->end() && $other->clock <= $this->end();
    }

    /**
     * The smallest range covering both. Only meaningful when they touch.
     */
    public function merge(self $other): self
    {
        $clock = min($this->clock, $other->clock);

        return new self($clock, max($this->end(), $other->end()) - $clock);
    }

    public function equals(self $other): bool
    {
        return $this->clock === $other->clock && $this->length === $other->length;
    }

    public function __toString(): string
    {
        return "[{$this->clock},{$this->end()})";
    }
}
