<?php

declare(strict_types=1);

namespace Hemp\Yjs\Exception;

/**
 * A declared length, element count, nesting depth, or cumulative allocation
 * crossed a configured decode limit.
 *
 * This is raised *before* the allocation happens. A hostile update that claims
 * to contain a two gigabyte string must cost the reader nothing.
 */
final class LimitExceeded extends DecodeException
{
    public static function byteLength(int $declared, int $limit, int $position): self
    {
        return new self(sprintf(
            'Declared byte length %d at offset %d exceeds the limit of %d.',
            $declared,
            $position,
            $limit,
        ));
    }

    public static function elementCount(int $declared, int $limit, int $position): self
    {
        return new self(sprintf(
            'Declared element count %d at offset %d exceeds the limit of %d.',
            $declared,
            $position,
            $limit,
        ));
    }

    public static function depth(int $limit, int $position): self
    {
        return new self(sprintf(
            'Nesting depth at offset %d exceeds the limit of %d.',
            $position,
            $limit,
        ));
    }

    public static function allocation(int $requested, int $used, int $limit): self
    {
        return new self(sprintf(
            'Allocating %d byte(s) would take total decode allocation to %d, past the limit of %d.',
            $requested,
            $used + $requested,
            $limit,
        ));
    }
}
