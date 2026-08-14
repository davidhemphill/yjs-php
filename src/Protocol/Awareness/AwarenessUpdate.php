<?php

declare(strict_types=1);

namespace Hemp\Yjs\Protocol\Awareness;

use Hemp\Yjs\Binary\DecodeLimits;
use Hemp\Yjs\Binary\Decoder;
use Hemp\Yjs\Binary\Encoder;
use Hemp\Yjs\Exception\DecodeException;
use Hemp\Yjs\Exception\LimitExceeded;

/**
 * A y-protocols awareness update: a list of clients, each with a clock and a
 * state.
 *
 * Unlike a document update this carries no causal structure — entries are
 * independent, and a later clock simply wins. That makes awareness cheap to
 * merge and impossible to reconstruct, which is the trade the protocol makes
 * deliberately: presence that is a little stale is fine, presence that costs
 * storage is not.
 */
final class AwarenessUpdate
{
    /**
     * @param  list<AwarenessEntry>  $entries
     */
    public function __construct(public readonly array $entries) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @throws LimitExceeded
     * @throws DecodeException
     */
    public static function read(Decoder $decoder, ?AwarenessLimits $limits = null): self
    {
        $limits ??= new AwarenessLimits;

        // Each entry is at least three bytes: client, clock, and a length.
        $count = $decoder->readCount(minimumBytesPerElement: 3);

        if ($count > $limits->maxClientsPerUpdate) {
            throw LimitExceeded::elementCount($count, $limits->maxClientsPerUpdate, $decoder->position());
        }

        $entries = [];

        for ($index = 0; $index < $count; $index++) {
            $client = $decoder->readVarUint();
            $clock = $decoder->readVarUint();

            $position = $decoder->position();
            $state = $decoder->readVarString();

            if (strlen($state) > $limits->maxStateBytes) {
                throw LimitExceeded::byteLength(strlen($state), $limits->maxStateBytes, $position);
            }

            $entries[] = new AwarenessEntry(
                $client,
                $clock,
                AwarenessEntry::isRemovalDocument($state) ? null : $state,
            );
        }

        return new self($entries);
    }

    /**
     * @throws DecodeException
     */
    public static function decode(string $bytes, ?AwarenessLimits $limits = null, ?DecodeLimits $decodeLimits = null): self
    {
        $decoder = new Decoder($bytes, $decodeLimits ?? new DecodeLimits);

        $update = self::read($decoder, $limits);
        $decoder->assertAtEnd();

        return $update;
    }

    public function write(Encoder $encoder): Encoder
    {
        $encoder->writeVarUint(count($this->entries));

        foreach ($this->entries as $entry) {
            $encoder->writeVarUint($entry->client)
                ->writeVarUint($entry->clock)
                ->writeVarString($entry->encodedState());
        }

        return $encoder;
    }

    public function encode(): string
    {
        return $this->write(new Encoder)->toBytes();
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /**
     * @return list<int>
     */
    public function clients(): array
    {
        return array_map(fn (AwarenessEntry $entry) => $entry->client, $this->entries);
    }
}
