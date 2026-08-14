<?php

declare(strict_types=1);

namespace Hemp\Yjs\Wire;

use Hemp\Yjs\Binary\Encoder;
use Hemp\Yjs\Id\Id;

/**
 * How an Item names its parent: either a root type's key, or the ID of the Item
 * the parent type lives in.
 *
 * A leading varUint on the wire says which — 1 for a key, 0 for an ID.
 */
final class ParentReference
{
    private function __construct(
        public readonly ?string $key,
        public readonly ?Id $id,
    ) {}

    public static function key(string $key): self
    {
        return new self($key, null);
    }

    public static function id(Id $id): self
    {
        return new self(null, $id);
    }

    public function isKey(): bool
    {
        return $this->key !== null;
    }

    public function write(Encoder $encoder): void
    {
        if ($this->key !== null) {
            $encoder->writeVarUint(1)->writeVarString($this->key);

            return;
        }

        $encoder->writeVarUint(0);
        $this->id?->write($encoder);
    }

    public function equals(self $other): bool
    {
        if ($this->key !== null || $other->key !== null) {
            return $this->key === $other->key;
        }

        return $this->id !== null && $other->id !== null && $this->id->equals($other->id);
    }

    public function __toString(): string
    {
        return $this->key !== null ? "key:{$this->key}" : "id:{$this->id}";
    }
}
