<?php

declare(strict_types=1);

namespace Yjs\Update;

use Yjs\Binary\DecodeLimits;
use Yjs\Binary\Decoder;
use Yjs\Binary\Encoder;
use Yjs\Id\DeleteSet;
use Yjs\Wire\Struct;

/**
 * A decoded Yjs V1 update: struct sections followed by a delete set.
 *
 * The representation is lossless but not interpreted. Every struct, every
 * reference, and every content payload is preserved exactly as it arrived —
 * enough to validate, compare, merge, and relay an update, and deliberately
 * not enough to know what the document says. Understanding the document would
 * mean reimplementing the shared types, which is where the divergence risk
 * lives and what this library exists to avoid.
 */
final class Update
{
    /**
     * @param  list<ClientStructs>  $sections
     */
    private function __construct(
        public readonly array $sections,
        public readonly DeleteSet $deleteSet,
    ) {}

    /**
     * @param  list<ClientStructs>  $sections
     */
    public static function of(array $sections, DeleteSet $deleteSet): self
    {
        return new self(array_values($sections), $deleteSet);
    }

    public static function empty(): self
    {
        return new self([], DeleteSet::empty());
    }

    public static function decode(string $bytes, ?DecodeLimits $limits = null): self
    {
        $decoder = new Decoder($bytes, $limits ?? new DecodeLimits);

        $update = self::read($decoder);
        $decoder->assertAtEnd();

        return $update;
    }

    public static function read(Decoder $decoder): self
    {
        // A section needs at least a struct count, a client, and a clock.
        $sectionCount = $decoder->readCount(minimumBytesPerElement: 3);

        $sections = [];

        for ($index = 0; $index < $sectionCount; $index++) {
            $sections[] = ClientStructs::read($decoder);
        }

        return new self($sections, DeleteSet::read($decoder));
    }

    /**
     * Re-encode the update.
     *
     * Sections keep the order they arrived in rather than being re-sorted. Yjs
     * writes clients in descending order, so an update that came from Yjs
     * re-encodes byte for byte; preserving the order additionally keeps a
     * handmade update, which may repeat or reorder clients, intact.
     */
    public function write(Encoder $encoder): Encoder
    {
        $encoder->writeVarUint(count($this->sections));

        foreach ($this->sections as $section) {
            $section->write($encoder);
        }

        return $this->deleteSet->write($encoder);
    }

    public function encode(): string
    {
        return $this->write(new Encoder)->toBytes();
    }

    /**
     * Every struct in the update, in wire order.
     *
     * @return list<Struct>
     */
    public function structs(): array
    {
        return array_merge(...array_map(
            fn (ClientStructs $section) => $section->structs,
            $this->sections,
        )) ?: [];
    }

    public function structCount(): int
    {
        $count = 0;

        foreach ($this->sections as $section) {
            $count += count($section->structs);
        }

        return $count;
    }

    /**
     * @return list<int>
     */
    public function clients(): array
    {
        return array_map(fn (ClientStructs $section) => $section->client, $this->sections);
    }

    public function isEmpty(): bool
    {
        return $this->structCount() === 0 && $this->deleteSet->isEmpty();
    }
}
