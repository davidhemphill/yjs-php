<?php

declare(strict_types=1);

namespace Yjs\Protocol\Sync;

use Yjs\Binary\DecodeLimits;
use Yjs\Binary\Encoder;
use Yjs\Exception\DecodeException;
use Yjs\Update\Update;

/**
 * "Here is what you were missing" — the reply to a {@see SyncStep1}.
 *
 * The payload is kept as bytes and decoded on demand. A relay usually wants to
 * forward it, and decoding a large update only to re-encode it unchanged is
 * work nobody asked for.
 */
final class SyncStep2 implements SyncMessage
{
    private ?Update $decoded = null;

    public function __construct(public readonly string $updateBytes) {}

    public static function of(Update $update): self
    {
        return new self($update->encode());
    }

    public function type(): SyncMessageType
    {
        return SyncMessageType::Step2;
    }

    /**
     * The update this carries.
     *
     * @throws DecodeException
     */
    public function update(?DecodeLimits $limits = null): Update
    {
        return $this->decoded ??= Update::decode($this->updateBytes, $limits);
    }

    public function write(Encoder $encoder): void
    {
        $encoder->writeVarUint(SyncMessageType::Step2->value)->writeVarBytes($this->updateBytes);
    }

    public function encode(): string
    {
        $encoder = new Encoder;
        $this->write($encoder);

        return $encoder->toBytes();
    }
}
