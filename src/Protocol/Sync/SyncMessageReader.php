<?php

declare(strict_types=1);

namespace Yjs\Protocol\Sync;

use Yjs\Binary\DecodeLimits;
use Yjs\Binary\Decoder;
use Yjs\Exception\DecodeException;
use Yjs\Exception\MalformedInput;
use Yjs\Id\StateVector;

/**
 * Reads y-protocols sync messages off the wire.
 *
 * The payload of a Step2 or Update is read as a bounded byte array and left
 * undecoded. That is deliberate: it means a malformed *update* inside a
 * well-formed *message* fails when the caller asks for it, at a point where
 * there is enough context to answer the peer properly, rather than aborting the
 * read of a frame that may carry several messages.
 */
final class SyncMessageReader
{
    private function __construct() {}

    /**
     * Read one message from wherever the decoder is positioned.
     *
     * @throws MalformedInput For a message type y-protocols does not define.
     * @throws DecodeException
     */
    public static function read(Decoder $decoder): SyncMessage
    {
        $position = $decoder->position();
        $type = SyncMessageType::tryFrom($decoder->readVarUint());

        return match ($type) {
            SyncMessageType::Step1 => new SyncStep1(
                StateVector::decode($decoder->readVarBytes()),
            ),
            SyncMessageType::Step2 => new SyncStep2($decoder->readVarBytes()),
            SyncMessageType::Update => new SyncUpdate($decoder->readVarBytes()),
            default => throw new MalformedInput(sprintf(
                'Unknown sync message type at offset %d; y-protocols defines 0, 1, and 2.',
                $position,
            )),
        };
    }

    /**
     * Read one message from a complete frame, rejecting anything left over.
     *
     * @throws DecodeException
     */
    public static function decode(string $bytes, ?DecodeLimits $limits = null): SyncMessage
    {
        $decoder = new Decoder($bytes, $limits ?? new DecodeLimits);

        $message = self::read($decoder);
        $decoder->assertAtEnd();

        return $message;
    }

    /**
     * Read every message in a frame.
     *
     * @return list<SyncMessage>
     *
     * @throws DecodeException
     */
    public static function decodeAll(string $bytes, ?DecodeLimits $limits = null): array
    {
        $decoder = new Decoder($bytes, $limits ?? new DecodeLimits);

        $messages = [];

        while ($decoder->hasMore()) {
            $messages[] = self::read($decoder);
        }

        return $messages;
    }
}
