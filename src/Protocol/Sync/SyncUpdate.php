<?php

declare(strict_types=1);

namespace Yjs\Protocol\Sync;

use Yjs\Binary\DecodeLimits;
use Yjs\Binary\Encoder;
use Yjs\Exception\DecodeException;
use Yjs\Update\Update;

/**
 * "Here is something new."
 *
 * Identical in shape to {@see SyncStep2} and read by the same code in
 * y-protocols, but distinct on the wire and distinct in meaning: a Step2
 * answers a question, an Update is unprompted. A server broadcasts these.
 */
final class SyncUpdate implements SyncMessage
{
    private ?Update $decoded = null;

    public function __construct(public readonly string $updateBytes) {}

    public static function of(Update $update): self
    {
        return new self($update->encode());
    }

    public function type(): SyncMessageType
    {
        return SyncMessageType::Update;
    }

    /**
     * @throws DecodeException
     */
    public function update(?DecodeLimits $limits = null): Update
    {
        return $this->decoded ??= Update::decode($this->updateBytes, $limits);
    }

    public function write(Encoder $encoder): void
    {
        $encoder->writeVarUint(SyncMessageType::Update->value)->writeVarBytes($this->updateBytes);
    }

    public function encode(): string
    {
        $encoder = new Encoder;
        $this->write($encoder);

        return $encoder->toBytes();
    }
}
