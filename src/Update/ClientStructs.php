<?php

declare(strict_types=1);

namespace Yjs\Update;

use Yjs\Binary\Decoder;
use Yjs\Binary\Encoder;
use Yjs\Id\Id;
use Yjs\Wire\Content\ContentReader;
use Yjs\Wire\Gc;
use Yjs\Wire\Item;
use Yjs\Wire\ParentReference;
use Yjs\Wire\Skip;
use Yjs\Wire\Struct;
use Yjs\Wire\StructInfo;

/**
 * One client's contiguous run of structs.
 *
 * Only the first clock is on the wire. Every struct after it starts where the
 * previous one ended, so the clocks are reconstructed by accumulating lengths —
 * which is why every content type's length has to match Yjs exactly. A length
 * that is wrong by one does not corrupt one struct, it shifts every struct
 * after it.
 */
final class ClientStructs
{
    /**
     * @param  list<Struct>  $structs
     */
    public function __construct(
        public readonly int $client,
        public readonly int $clock,
        public readonly array $structs,
    ) {}

    public static function read(Decoder $decoder): self
    {
        // Every struct is at least its own info byte.
        $structCount = $decoder->readCount(minimumBytesPerElement: 1);

        $client = $decoder->readVarUint();
        $clock = $decoder->readVarUint();

        $structs = [];
        $nextClock = $clock;

        for ($index = 0; $index < $structCount; $index++) {
            $struct = self::readStruct($decoder, $client, $nextClock);

            $structs[] = $struct;
            $nextClock += $struct->length();
        }

        return new self($client, $clock, $structs);
    }

    public function write(Encoder $encoder): Encoder
    {
        $encoder->writeVarUint(count($this->structs))
            ->writeVarUint($this->client)
            ->writeVarUint($this->clock);

        foreach ($this->structs as $struct) {
            $struct->write($encoder);
        }

        return $encoder;
    }

    /**
     * One past the last clock this section describes, gaps included.
     */
    public function endClock(): int
    {
        $end = $this->clock;

        foreach ($this->structs as $struct) {
            $end += $struct->length();
        }

        return $end;
    }

    /**
     * How far this client's history is known without a hole in it.
     *
     * A state vector says "I have everything below clock N", so it can only
     * ever describe a prefix. A section that begins partway through, or that
     * contains a {@see Skip}, has nothing contiguous past that point to
     * promise — so counting stops there rather than at {@see self::endClock()}.
     *
     * Yjs applies the same two rules in `encodeStateVectorFromUpdate`, and
     * getting this wrong would be worse than an off-by-one: it would claim to
     * hold structs on the far side of a gap and stop peers from ever sending
     * them.
     */
    public function contiguousEndClock(): int
    {
        if ($this->clock !== 0) {
            return 0;
        }

        $end = 0;

        foreach ($this->structs as $struct) {
            if ($struct instanceof Skip) {
                break;
            }

            $end = $struct->id()->clock + $struct->length();
        }

        return $end;
    }

    private static function readStruct(Decoder $decoder, int $client, int $clock): Struct
    {
        $info = $decoder->readUint8();
        $id = new Id($client, $clock);

        return match (StructInfo::contentRef($info)) {
            StructInfo::GC_REF => new Gc($id, $decoder->readVarUint()),
            StructInfo::SKIP_REF => new Skip($id, $decoder->readVarUint()),
            default => self::readItem($decoder, $id, $info),
        };
    }

    private static function readItem(Decoder $decoder, Id $id, int $info): Item
    {
        $origin = StructInfo::hasOrigin($info) ? Id::read($decoder) : null;
        $rightOrigin = StructInfo::hasRightOrigin($info) ? Id::read($decoder) : null;

        $parent = null;
        $parentSub = null;

        // An Item with an origin on either side inherits its parent from that
        // neighbour, so neither the parent nor the parentSub is on the wire —
        // regardless of what the parentSub bit says.
        if (StructInfo::carriesParent($info)) {
            $parent = $decoder->readVarUint() === 1
                ? ParentReference::key($decoder->readVarString())
                : ParentReference::id(Id::read($decoder));

            if (StructInfo::hasParentSub($info)) {
                $parentSub = $decoder->readVarString();
            }
        }

        return new Item(
            $id,
            $info,
            $origin,
            $rightOrigin,
            $parent,
            $parentSub,
            ContentReader::read($decoder, StructInfo::contentRef($info)),
        );
    }
}
