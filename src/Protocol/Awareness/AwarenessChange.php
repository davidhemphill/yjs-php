<?php

declare(strict_types=1);

namespace Hemp\Yjs\Protocol\Awareness;

/**
 * What an awareness operation actually altered.
 *
 * A server needs this to decide whether anything is worth broadcasting: most
 * awareness traffic is a client repeating itself, and forwarding every message
 * to every peer regardless of whether it changed anything is how a presence
 * indicator turns into a bandwidth problem.
 */
final class AwarenessChange
{
    /**
     * @param  list<int>  $added
     * @param  list<int>  $updated
     * @param  list<int>  $removed
     */
    public function __construct(
        public readonly array $added = [],
        public readonly array $updated = [],
        public readonly array $removed = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->added === [] && $this->updated === [] && $this->removed === [];
    }

    /**
     * Every client this touched, in a stable order.
     *
     * @return list<int>
     */
    public function clients(): array
    {
        $clients = array_values(array_unique([...$this->added, ...$this->updated, ...$this->removed]));

        sort($clients);

        return $clients;
    }
}
