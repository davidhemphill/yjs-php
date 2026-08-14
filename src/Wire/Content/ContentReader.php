<?php

declare(strict_types=1);

namespace Hemp\Yjs\Wire\Content;

use Hemp\Yjs\Binary\Decoder;
use Hemp\Yjs\Exception\MalformedInput;

/**
 * Reads an Item's content by its reference number.
 *
 * Refs 0 and 10 never reach here: they mark a GC and a Skip, which are whole
 * structs rather than the payload of an Item.
 */
final class ContentReader
{
    private function __construct() {}

    /**
     * @throws MalformedInput For a reference number Profile 1 does not define.
     */
    public static function read(Decoder $decoder, int $ref): Content
    {
        return match ($ref) {
            1 => Deleted::read($decoder),
            2 => Json::read($decoder),
            3 => Binary::read($decoder),
            4 => Text::read($decoder),
            5 => Embed::read($decoder),
            6 => Format::read($decoder),
            7 => SharedType::read($decoder),
            8 => AnyValues::read($decoder),
            9 => SubDocument::read($decoder),
            default => throw new MalformedInput(sprintf(
                'Unknown content reference %d at offset %d; Profile 1 defines 1 through 9.',
                $ref,
                $decoder->position(),
            )),
        };
    }
}
