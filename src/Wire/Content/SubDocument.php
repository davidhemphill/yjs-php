<?php

declare(strict_types=1);

namespace Yjs\Wire\Content;

use Yjs\Binary\Decoder;
use Yjs\Binary\Encoder;

/**
 * Content ref 9 — a nested document: its GUID and its options.
 *
 * Yjs rebuilds the options from the live `Doc` when it writes one of these, so
 * Yjs itself does not necessarily reproduce the bytes it read. We keep what
 * arrived and write that back, which is both lossless and what a relay should
 * do — see the note in compatibility/profile-1.md.
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
