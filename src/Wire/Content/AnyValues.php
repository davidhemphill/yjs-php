<?php

declare(strict_types=1);

namespace Hemp\Yjs\Wire\Content;

use Hemp\Yjs\Binary\Decoder;
use Hemp\Yjs\Binary\Encoder;

/**
 * Content ref 8 — a list of lib0 `any` values, one clock each.
 *
 * Unlike {@see Json}, this content is written with lib0's own `any` encoding,
 * which round-trips byte-identically through PHP. So these values are decoded
 * rather than kept as text.
 */
final class AnyValues implements Sliceable
{
    /**
     * @return array{0: Content, 1: Content}
     */
    public function split(int $offset): array
    {
        return [
            new self(array_slice($this->values, 0, $offset)),
            new self(array_slice($this->values, $offset)),
        ];
    }

    /**
     * @param  list<mixed>  $values
     */
    public function __construct(public readonly array $values) {}

    public static function read(Decoder $decoder): self
    {
        // Each value is at least its own one-byte tag.
        $count = $decoder->readCount(minimumBytesPerElement: 1);

        $values = [];

        for ($index = 0; $index < $count; $index++) {
            $values[] = $decoder->readAny();
        }

        return new self($values);
    }

    public function ref(): int
    {
        return 8;
    }

    public function length(): int
    {
        return count($this->values);
    }

    public function write(Encoder $encoder): void
    {
        $encoder->writeVarUint(count($this->values));

        foreach ($this->values as $value) {
            $encoder->writeAny($value);
        }
    }
}
