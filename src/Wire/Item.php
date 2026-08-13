<?php

declare(strict_types=1);

namespace Yjs\Wire;

use Yjs\Binary\Encoder;
use Yjs\Id\Id;
use Yjs\Wire\Content\Content;

/**
 * A struct carrying content, together with the references that place it.
 *
 * The info byte is kept exactly as it arrived rather than recomputed on write.
 * That is not laziness: `parentSub`'s info bit can legitimately be set on an
 * Item whose `parentSub` field is *not* on the wire, because Yjs sets the bit
 * from the value but writes the field only when the Item has no origin to
 * inherit a parent from. Recomputing the byte from the fields we hold would
 * quietly drop that bit and produce different bytes than we received.
 */
final class Item implements Struct
{
    public function __construct(
        public readonly Id $id,
        public readonly int $info,
        public readonly ?Id $origin,
        public readonly ?Id $rightOrigin,
        public readonly ?ParentReference $parent,
        public readonly ?string $parentSub,
        public readonly Content $content,
    ) {}

    public function id(): Id
    {
        return $this->id;
    }

    public function length(): int
    {
        return $this->content->length();
    }

    public function contentRef(): int
    {
        return StructInfo::contentRef($this->info);
    }

    /**
     * Whether this Item spells out its own parent, rather than inheriting it
     * from a neighbour it names.
     */
    public function carriesParent(): bool
    {
        return StructInfo::carriesParent($this->info);
    }

    public function write(Encoder $encoder): void
    {
        $encoder->writeUint8($this->info);

        $this->origin?->write($encoder);
        $this->rightOrigin?->write($encoder);

        // Mirrors Yjs exactly: the parent block, and the parentSub inside it,
        // are written only when there is no origin on either side.
        if ($this->origin === null && $this->rightOrigin === null) {
            $this->parent?->write($encoder);

            if ($this->parentSub !== null) {
                $encoder->writeVarString($this->parentSub);
            }
        }

        $this->content->write($encoder);
    }
}
