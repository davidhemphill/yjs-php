<?php

declare(strict_types=1);

namespace Yjs\Wire\Content;

use Yjs\Binary\Encoder;

/**
 * The payload of an Item.
 *
 * Yjs numbers these one through nine, and the number lives in the low five bits
 * of the struct's info byte. The numbering is a wire constant.
 *
 * | Ref | Yjs class       | Here                    |
 * |-----|-----------------|-------------------------|
 * | 1   | ContentDeleted  | {@see Deleted}          |
 * | 2   | ContentJSON     | {@see Json}             |
 * | 3   | ContentBinary   | {@see Binary}           |
 * | 4   | ContentString   | {@see Text}             |
 * | 5   | ContentEmbed    | {@see Embed}            |
 * | 6   | ContentFormat   | {@see Format}           |
 * | 7   | ContentType     | {@see SharedType}       |
 * | 8   | ContentAny      | {@see AnyValues}        |
 * | 9   | ContentDoc      | {@see SubDocument}      |
 *
 * Refs 0 and 10 are not content at all — they mark a GC and a Skip struct,
 * which have no Item around them.
 */
interface Content
{
    /**
     * The content reference number, as it appears in the info byte.
     */
    public function ref(): int;

    /**
     * How many clocks this content occupies.
     *
     * This is what advances a client's clock from one struct to the next, so it
     * has to agree with Yjs exactly. Most content is one clock regardless of
     * size; the exceptions are string content, which counts UTF-16 units, and
     * the list-shaped contents, which count elements.
     */
    public function length(): int;

    public function write(Encoder $encoder): void;
}
