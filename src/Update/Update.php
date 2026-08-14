<?php

declare(strict_types=1);

namespace Yjs\Update;

use Yjs\Binary\DecodeLimits;
use Yjs\Binary\Decoder;
use Yjs\Binary\Encoder;
use Yjs\Exception\InvalidUpdate;
use Yjs\Id\ClockRange;
use Yjs\Id\DeleteSet;
use Yjs\Id\StateVector;
use Yjs\Wire\Skip;
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
     * Check that this update is structurally sound and within policy.
     *
     * @throws InvalidUpdate
     */
    public function validate(?SemanticLimits $limits = null): self
    {
        UpdateValidator::validate($this, $limits);

        return $this;
    }

    /**
     * How much of each client's history this update carries.
     *
     * Only the contiguous prefix counts, and a client whose prefix is empty is
     * left out entirely rather than recorded as zero — both matching
     * `encodeStateVectorFromUpdate`. See {@see ClientStructs::contiguousEndClock()}
     * for why a gap ends the count.
     */
    public function stateVector(): StateVector
    {
        $vector = StateVector::empty();

        foreach ($this->sections as $section) {
            $clock = $section->contiguousEndClock();

            if ($clock !== 0) {
                $vector = $vector->raisedTo($section->client, $clock);
            }
        }

        return $vector;
    }

    /**
     * Combine this update with others into one.
     */
    public function merge(self ...$others): self
    {
        return UpdateMerger::merge($this, ...$others);
    }

    /**
     * Combine any number of updates, including none.
     */
    public static function mergeAll(self ...$updates): self
    {
        return UpdateMerger::merge(...$updates);
    }

    /**
     * The part of this update a peer with the given state vector is missing.
     */
    public function diff(StateVector $have): self
    {
        return UpdateDiffer::diff($this, $have);
    }

    /**
     * Whether this update already accounts for everything in another one.
     *
     * The question a read-only session has to answer: a client that cannot
     * write still completes a sync handshake and will happily send back state
     * it believes we need. If that state is entirely redundant the handshake
     * can be acknowledged; if any of it is new, the client is introducing
     * document state it is not allowed to introduce.
     *
     * Skips are ignored on both sides. A skip asserts a hole in the sender's
     * knowledge rather than any document content, so it neither needs covering
     * nor covers anything.
     */
    public function contains(self $other): bool
    {
        $coverage = $this->coverage();

        foreach ($other->sections as $section) {
            foreach ($section->structs as $struct) {
                if ($struct instanceof Skip || $struct->length() === 0) {
                    continue;
                }

                $range = new ClockRange($struct->id()->clock, $struct->length());

                if (! self::covers($coverage[$section->client] ?? [], $range)) {
                    return false;
                }
            }
        }

        return $other->deleteSet->isSubsetOf($this->deleteSet);
    }

    /**
     * The clock ranges this update actually carries content for, per client,
     * coalesced so that adjacent structs read as one run.
     *
     * @return array<int, list<ClockRange>>
     */
    public function coverage(): array
    {
        $ranges = [];

        foreach ($this->sections as $section) {
            foreach ($section->structs as $struct) {
                if ($struct instanceof Skip || $struct->length() === 0) {
                    continue;
                }

                $ranges[$section->client][] = new ClockRange($struct->id()->clock, $struct->length());
            }
        }

        // Reuse the delete set's coalescing, which is the same problem: fold a
        // list of ranges into the fewest that describe it.
        return DeleteSet::fromArray($ranges)->normalized()->toArray();
    }

    /**
     * @param  list<ClockRange>  $ranges  Already coalesced.
     */
    private static function covers(array $ranges, ClockRange $needle): bool
    {
        foreach ($ranges as $range) {
            if ($range->containsRange($needle)) {
                return true;
            }
        }

        return false;
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
