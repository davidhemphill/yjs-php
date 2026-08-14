<?php

declare(strict_types=1);

namespace Yjs\Protocol\Sync;

use Yjs\Binary\Encoder;
use Yjs\Id\StateVector;
use Yjs\Update\Update;

/**
 * "Here is what I already have."
 *
 * The opening move of a sync, and the only one a read-only peer is always
 * allowed to make: it asserts nothing about the document, it only asks.
 */
final class SyncStep1 implements SyncMessage
{
    public function __construct(public readonly StateVector $stateVector) {}

    public function type(): SyncMessageType
    {
        return SyncMessageType::Step1;
    }

    public function write(Encoder $encoder): void
    {
        $encoder->writeVarUint(SyncMessageType::Step1->value)
            ->writeVarBytes($this->stateVector->encode());
    }

    public function encode(): string
    {
        $encoder = new Encoder;
        $this->write($encoder);

        return $encoder->toBytes();
    }

    /**
     * The reply this asks for: everything the sender is missing.
     */
    public function answer(Update $resident): SyncStep2
    {
        return new SyncStep2($resident->diff($this->stateVector)->encode());
    }
}
