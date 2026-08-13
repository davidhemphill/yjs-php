<?php

declare(strict_types=1);

namespace Yjs\Wire;

/**
 * The layout of a struct's leading info byte.
 *
 * ```text
 *   bit  8 7 6 5 4 3 2 1
 *        │ │ │ └─┴─┴─┴─┴── content reference (0 = GC, 10 = Skip, 1-9 = Item content)
 *        │ │ └──────────── parentSub is present
 *        │ └────────────── right origin is present
 *        └──────────────── origin is present
 * ```
 *
 * These are wire constants, not ours to renumber.
 */
final class StructInfo
{
    public const int CONTENT_REF = 0b0001_1111;

    public const int HAS_PARENT_SUB = 0b0010_0000;

    public const int HAS_RIGHT_ORIGIN = 0b0100_0000;

    public const int HAS_ORIGIN = 0b1000_0000;

    /**
     * Content ref 0: space that has been garbage collected.
     */
    public const int GC_REF = 0;

    /**
     * Content ref 10: clocks the sender could not supply.
     */
    public const int SKIP_REF = 10;

    private function __construct() {}

    /**
     * Whether this struct has to spell out its own parent.
     *
     * An Item that has an origin or a right origin inherits its parent from the
     * neighbour it names, so the parent fields are simply absent from the wire.
     * Only an Item with neither carries them — which is also the only case where
     * `parentSub` is written, *even though its info bit can be set regardless*.
     * Reading `parentSub` whenever that bit is set would desynchronize the whole
     * remaining stream.
     */
    public static function carriesParent(int $info): bool
    {
        return ($info & (self::HAS_ORIGIN | self::HAS_RIGHT_ORIGIN)) === 0;
    }

    public static function contentRef(int $info): int
    {
        return $info & self::CONTENT_REF;
    }

    public static function hasOrigin(int $info): bool
    {
        return ($info & self::HAS_ORIGIN) !== 0;
    }

    public static function hasRightOrigin(int $info): bool
    {
        return ($info & self::HAS_RIGHT_ORIGIN) !== 0;
    }

    public static function hasParentSub(int $info): bool
    {
        return ($info & self::HAS_PARENT_SUB) !== 0;
    }
}
