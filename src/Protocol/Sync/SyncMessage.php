<?php

declare(strict_types=1);

namespace Yjs\Protocol\Sync;

use Yjs\Binary\Encoder;

/**
 * A y-protocols sync message.
 *
 * Writing works against an encoder that may already hold other bytes, because
 * these messages never travel alone: Hocuspocus prefixes each one with the
 * document it belongs to. Nothing here assumes it owns the buffer.
 *
 * {@see SyncMessageReader} reads them back.
 */
interface SyncMessage
{
    public function type(): SyncMessageType;

    /**
     * Append this message, type byte included.
     */
    public function write(Encoder $encoder): void;

    /**
     * The message on its own, for callers that do have a buffer to themselves.
     */
    public function encode(): string;
}
