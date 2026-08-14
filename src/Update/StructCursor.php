<?php

declare(strict_types=1);

namespace Yjs\Update;

use Yjs\Wire\Skip;
use Yjs\Wire\Struct;

/**
 * A read position in one update's struct stream, matching Yjs's
 * `LazyStructReader` with `filterSkips` on.
 *
 * Skips are dropped as they are read rather than filtered up front, because
 * that is what the merge expects: a skip states what the *sender* was missing,
 * and another update in the same merge may well supply it.
 */
final class StructCursor
{
    /** @var list<Struct> */
    private readonly array $structs;

    private int $index = 0;

    public function __construct(Update $update)
    {
        $this->structs = array_values(array_filter(
            $update->structs(),
            fn (Struct $struct) => ! $struct instanceof Skip,
        ));
    }

    public function current(): ?Struct
    {
        return $this->structs[$this->index] ?? null;
    }

    public function next(): ?Struct
    {
        $this->index++;

        return $this->current();
    }
}
