<?php

declare(strict_types=1);

namespace Hemp\Yjs\Protocol\Awareness;

use Hemp\Yjs\Binary\SafeInteger;

/**
 * One client's awareness state at one clock.
 *
 * The state is carried as JSON text, exactly as it arrived. Awareness payloads
 * are application-defined — cursor positions, names, colours — and this library
 * has no reason to look inside one. Parsing and re-serializing would also
 * change the bytes, since `JSON.stringify` and PHP's `json_encode` do not agree
 * on escaping or key order.
 *
 * A null state is not a state at all: it is the removal of one. y-protocols
 * encodes that as the JSON document `null`, which is why the two cases share a
 * field rather than being separate messages.
 */
final class AwarenessEntry
{
    public function __construct(
        public readonly int $client,
        public readonly int $clock,
        public readonly ?string $state,
    ) {
        SafeInteger::assertNonNegative($client);
        SafeInteger::assertNonNegative($clock);
    }

    /**
     * A client going away.
     */
    public static function removal(int $client, int $clock): self
    {
        return new self($client, $clock, null);
    }

    public function isRemoval(): bool
    {
        return $this->state === null;
    }

    /**
     * The JSON text for this entry, with removals spelled `null`.
     */
    public function encodedState(): string
    {
        return $this->state ?? 'null';
    }

    /**
     * Whether a JSON document is the removal marker.
     *
     * Compared after trimming JSON's own whitespace, because `null` and
     * ` null ` are the same document and only one of them is what a naive
     * string comparison would catch.
     */
    public static function isRemovalDocument(string $json): bool
    {
        return trim($json, " \t\n\r") === 'null';
    }
}
