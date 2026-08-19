<?php

declare(strict_types=1);

namespace Hemp\Yjs\Protocol\Awareness;

/**
 * What an awareness operation actually altered.
 *
 * y-protocols draws a line worth keeping: its `change` event fires when a
 * state is different, and its `update` event also fires when a client merely
 * renewed its clock without changing anything. The renewal looks like noise
 * and is not — it is the heartbeat. A client re-announces itself every
 * fifteen seconds, the server broadcasts that renewal, and every peer's
 * thirty-second expiry timer starts over. Broadcast only the `change` set and
 * every idle cursor in the room evaporates on a timer.
 *
 * So `refreshed` is carried separately: not a change to any state, but still
 * something the wire has to repeat.
 */
final class AwarenessChange
{
    /**
     * @param  list<int>  $added
     * @param  list<int>  $updated  Clients whose state is different.
     * @param  list<int>  $removed
     * @param  list<int>  $refreshed  Clients that renewed their clock with the same state.
     */
    public function __construct(
        public readonly array $added = [],
        public readonly array $updated = [],
        public readonly array $removed = [],
        public readonly array $refreshed = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->added === [] && $this->updated === [] && $this->removed === [] && $this->refreshed === [];
    }

    /**
     * Every client this touched, in a stable order.
     *
     * @return list<int>
     */
    public function clients(): array
    {
        $clients = array_values(array_unique([
            ...$this->added, ...$this->updated, ...$this->removed, ...$this->refreshed,
        ]));

        sort($clients);

        return $clients;
    }
}
