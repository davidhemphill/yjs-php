<?php

declare(strict_types=1);

namespace Hemp\Yjs\Id;

use Hemp\Yjs\Binary\Decoder;
use Hemp\Yjs\Binary\Encoder;
use Hemp\Yjs\Binary\SafeInteger;

/**
 * A position in one client's history: which client wrote it, and when.
 *
 * Every struct on the wire is addressed by one of these, and every reference
 * between structs is one too. Immutable, because an ID that could be mutated
 * after a struct is placed would silently relocate it.
 */
final class Id
{
    public function __construct(
        public readonly int $client,
        public readonly int $clock,
    ) {
        SafeInteger::assertNonNegative($client);
        SafeInteger::assertNonNegative($clock);
    }

    public static function read(Decoder $decoder): self
    {
        return new self($decoder->readVarUint(), $decoder->readVarUint());
    }

    public function write(Encoder $encoder): Encoder
    {
        return $encoder->writeVarUint($this->client)->writeVarUint($this->clock);
    }

    /**
     * The same client, a given distance further along.
     */
    public function advanced(int $by): self
    {
        return new self($this->client, $this->clock + $by);
    }

    public function equals(self $other): bool
    {
        return $this->client === $other->client && $this->clock === $other->clock;
    }

    public function __toString(): string
    {
        return "{$this->client}:{$this->clock}";
    }
}
