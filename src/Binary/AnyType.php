<?php

declare(strict_types=1);

namespace Yjs\Binary;

/**
 * The lib0 "any" type tags, exactly as `writeAny` emits them.
 *
 * The tags count *down* from 127 because lib0 reads them through a lookup
 * table indexed by `127 - tag`. The numbers are wire constants; they are not
 * ours to renumber.
 */
enum AnyType: int
{
    case Undefined = 127;
    case Null = 126;
    case Integer = 125;
    case Float32 = 124;
    case Float64 = 123;
    case BigInt64 = 122;
    case False = 121;
    case True = 120;
    case String = 119;
    case Object = 118;
    case Array = 117;
    case Bytes = 116;
}
