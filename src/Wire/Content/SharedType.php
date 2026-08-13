<?php

declare(strict_types=1);

namespace Yjs\Wire\Content;

use Yjs\Binary\Decoder;
use Yjs\Binary\Encoder;
use Yjs\Exception\MalformedInput;

/**
 * Content ref 7 — a nested shared type: a Y.Map, Y.Array, Y.Text, or one of the
 * XML types.
 *
 * The type is carried as its reference number and, for the two types that have
 * one, a name. Nothing else: the *contents* of a nested type are not here, they
 * are separate structs elsewhere in the update whose parent points back at this
 * Item. So an opaque server can relay a nested type perfectly without ever
 * materializing one.
 */
final class SharedType implements Content
{
    public const int Y_ARRAY = 0;

    public const int Y_MAP = 1;

    public const int Y_TEXT = 2;

    public const int Y_XML_ELEMENT = 3;

    public const int Y_XML_FRAGMENT = 4;

    public const int Y_XML_HOOK = 5;

    public const int Y_XML_TEXT = 6;

    /**
     * The two type refs that carry a name after them — a tag name for an XML
     * element, a hook name for a hook. Every other type writes nothing more.
     */
    private const array NAMED_TYPES = [self::Y_XML_ELEMENT, self::Y_XML_HOOK];

    public function __construct(
        public readonly int $typeRef,
        public readonly ?string $name = null,
    ) {}

    public static function read(Decoder $decoder): self
    {
        $position = $decoder->position();
        $typeRef = $decoder->readVarUint();

        if ($typeRef > self::Y_XML_TEXT) {
            throw new MalformedInput(sprintf(
                'Unknown shared type reference %d at offset %d; Profile 1 defines 0 through %d.',
                $typeRef,
                $position,
                self::Y_XML_TEXT,
            ));
        }

        return new self($typeRef, self::isNamed($typeRef) ? $decoder->readVarString() : null);
    }

    public function ref(): int
    {
        return 7;
    }

    public function length(): int
    {
        return 1;
    }

    public function write(Encoder $encoder): void
    {
        $encoder->writeVarUint($this->typeRef);

        if ($this->name !== null) {
            $encoder->writeVarString($this->name);
        }
    }

    public static function isNamed(int $typeRef): bool
    {
        return in_array($typeRef, self::NAMED_TYPES, true);
    }
}
