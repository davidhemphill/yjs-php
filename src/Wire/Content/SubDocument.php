<?php

declare(strict_types=1);

namespace Hemp\Yjs\Wire\Content;

use Hemp\Yjs\Binary\Decoder;
use Hemp\Yjs\Binary\Encoder;

/**
 * Content ref 9 — a nested document: its GUID and its options.
 *
 * The options are kept exactly as they arrived, which is what Yjs's own
 * update-level code does. Yjs normalizes them to `gc`, `autoLoad`, and `meta`
 * when a ContentDoc is *constructed*, so anything Yjs originates is already
 * normalized before it reaches the wire and every round trip is byte-identical.
 * Only an update from somewhere else can carry anything more, and there
 * `mergeUpdates` preserves it while the live-document path drops it. An
 * update-level library matches the former.
 */
final class SubDocument implements Content
{
    /**
     * @param  mixed  $options  A decoded lib0 `any`, normally an object.
     */
    public function __construct(
        public readonly string $guid,
        public readonly mixed $options,
    ) {}

    public static function read(Decoder $decoder): self
    {
        return new self($decoder->readVarString(), $decoder->readAny());
    }

    public function ref(): int
    {
        return 9;
    }

    public function length(): int
    {
        return 1;
    }

    public function write(Encoder $encoder): void
    {
        $encoder->writeVarString($this->guid)->writeAny($this->options);
    }
}
