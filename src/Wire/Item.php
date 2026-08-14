<?php

declare(strict_types=1);

namespace Hemp\Yjs\Wire;

use Hemp\Yjs\Binary\Encoder;
use Hemp\Yjs\Exception\EncodeException;
use Hemp\Yjs\Id\Id;
use Hemp\Yjs\Wire\Content\Content;
use Hemp\Yjs\Wire\Content\Sliceable;

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

    /**
     * Build an Item and derive its info byte from its fields.
     *
     * This is the formula in Yjs's `Item.write`, and it is only safe for an
     * Item we are constructing ourselves. An Item read off the wire keeps the
     * info byte it arrived with, because that byte can carry a parentSub bit
     * whose field is absent and this formula would drop it.
     */
    public static function compose(
        Id $id,
        ?Id $origin,
        ?Id $rightOrigin,
        ?ParentReference $parent,
        ?string $parentSub,
        Content $content,
    ): self {
        $info = ($content->ref() & StructInfo::CONTENT_REF)
            | ($origin === null ? 0 : StructInfo::HAS_ORIGIN)
            | ($rightOrigin === null ? 0 : StructInfo::HAS_RIGHT_ORIGIN)
            | ($parentSub === null ? 0 : StructInfo::HAS_PARENT_SUB);

        return new self($id, $info, $origin, $rightOrigin, $parent, $parentSub, $content);
    }

    public function id(): Id
    {
        return $this->id;
    }

    /**
     * Drop the first `$offset` clocks.
     *
     * The tail becomes an Item in its own right, and it gains an origin — the
     * clock immediately before it — because that is what now places it. Having
     * an origin also means it no longer spells out its parent, which is why the
     * info byte has to be recomputed rather than carried over.
     *
     * @throws EncodeException When the content occupies a single clock and has
     *                         no offset inside it to cut at.
     */
    public function sliceFrom(int $offset): Struct
    {
        if ($offset === 0) {
            return $this;
        }

        if (! $this->content instanceof Sliceable) {
            throw new EncodeException(sprintf(
                'Content reference %d occupies one clock and cannot be sliced at offset %d.',
                $this->content->ref(),
                $offset,
            ));
        }

        [, $right] = $this->content->split($offset);

        return self::compose(
            $this->id->advanced($offset),
            $this->id->advanced($offset - 1),
            $this->rightOrigin,
            $this->parent,
            $this->parentSub,
            $right,
        );
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

    public function normalized(): Struct
    {
        return self::compose(
            $this->id,
            $this->origin,
            $this->rightOrigin,
            $this->parent,
            $this->parentSub,
            $this->content,
        );
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
