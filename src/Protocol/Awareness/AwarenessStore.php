<?php

declare(strict_types=1);

namespace Hemp\Yjs\Protocol\Awareness;

use Hemp\Yjs\Exception\LimitExceeded;

/**
 * Who is currently present in a document, and how recently each said so.
 *
 * This is the server's view of awareness. It has no local state of its own — it
 * is not a participant, it is the thing everyone else's presence passes through
 * — which is why y-protocols' rule about never letting a remote peer clear the
 * *local* client is absent here. There is no local client to protect.
 *
 * Time is passed in rather than read. A store that called the clock itself
 * could not be tested for expiry without sleeping, and the server needs to
 * drive expiry from its own loop anyway.
 */
final class AwarenessStore
{
    /** @var array<int, array{clock: int, state: ?string, lastUpdated: int}> */
    private array $clients = [];

    public function __construct(private readonly AwarenessLimits $limits = new AwarenessLimits) {}

    /**
     * Apply an update, returning only what actually changed.
     *
     * The acceptance rule is y-protocols': a higher clock always wins, and a
     * removal is additionally accepted at the *same* clock. That second case is
     * what lets a disconnect be announced by whoever noticed it, without having
     * to invent a clock the departed client never sent.
     *
     * @throws LimitExceeded When accepting would track more clients than allowed.
     */
    public function apply(AwarenessUpdate $update, int $now): AwarenessChange
    {
        $added = [];
        $updated = [];
        $removed = [];

        foreach ($update->entries as $entry) {
            $known = $this->clients[$entry->client] ?? null;
            $currentClock = $known['clock'] ?? 0;

            $accepted = $currentClock < $entry->clock
                || ($currentClock === $entry->clock && $entry->isRemoval() && $known !== null && $known['state'] !== null);

            if (! $accepted) {
                continue;
            }

            if ($entry->isRemoval()) {
                if ($known !== null) {
                    $removed[] = $entry->client;
                }

                // The clock is kept even though the state is gone. Forgetting it
                // would let a stale message from the departed client reinstate
                // them, since any clock beats no clock.
                $this->clients[$entry->client] = [
                    'clock' => $entry->clock,
                    'state' => null,
                    'lastUpdated' => $now,
                ];

                continue;
            }

            if ($known === null || $known['state'] === null) {
                $this->assertRoomForAnother();
                $added[] = $entry->client;
            } elseif ($known['state'] !== $entry->state) {
                $updated[] = $entry->client;
            }

            $this->clients[$entry->client] = [
                'clock' => $entry->clock,
                'state' => $entry->state,
                'lastUpdated' => $now,
            ];
        }

        return new AwarenessChange($added, $updated, $removed);
    }

    /**
     * Drop clients that have not been heard from inside the timeout.
     *
     * Awareness has no disconnect signal that can be relied on — a dropped
     * connection produces nothing at all — so presence has to be forgotten on a
     * timer or it accumulates for the life of the process.
     */
    public function expire(int $now, int $timeoutMs = AwarenessLimits::OUTDATED_TIMEOUT_MS): AwarenessChange
    {
        $removed = [];

        foreach ($this->clients as $client => $entry) {
            if ($entry['state'] !== null && $now - $entry['lastUpdated'] >= $timeoutMs) {
                $this->clients[$client]['state'] = null;
                $this->clients[$client]['clock']++;
                $removed[] = $client;
            }
        }

        return new AwarenessChange(removed: $removed);
    }

    /**
     * An update announcing the current state of the given clients.
     *
     * @param  list<int>|null  $clients  Null for everyone present.
     */
    public function updateFor(?array $clients = null): AwarenessUpdate
    {
        $clients ??= $this->presentClients();
        $entries = [];

        foreach ($clients as $client) {
            $entry = $this->clients[$client] ?? null;

            if ($entry !== null) {
                $entries[] = new AwarenessEntry($client, $entry['clock'], $entry['state']);
            }
        }

        return new AwarenessUpdate($entries);
    }

    /**
     * An update telling everyone that these clients are gone.
     *
     * @param  list<int>  $clients
     */
    public function removalFor(array $clients): AwarenessUpdate
    {
        $entries = [];

        foreach ($clients as $client) {
            $entry = $this->clients[$client] ?? null;

            if ($entry !== null && $entry['state'] !== null) {
                // One past the clock they were last seen at, so the removal
                // wins against their own last message wherever it arrives.
                $entries[] = AwarenessEntry::removal($client, $entry['clock'] + 1);
            }
        }

        return new AwarenessUpdate($entries);
    }

    /**
     * Forget these clients entirely, as a connection closing should.
     *
     * @param  list<int>  $clients
     */
    public function forget(array $clients): AwarenessChange
    {
        $removed = [];

        foreach ($clients as $client) {
            if (isset($this->clients[$client])) {
                if ($this->clients[$client]['state'] !== null) {
                    $removed[] = $client;
                }

                unset($this->clients[$client]);
            }
        }

        return new AwarenessChange(removed: $removed);
    }

    public function stateFor(int $client): ?string
    {
        return $this->clients[$client]['state'] ?? null;
    }

    public function clockFor(int $client): int
    {
        return $this->clients[$client]['clock'] ?? 0;
    }

    public function knows(int $client): bool
    {
        return ($this->clients[$client]['state'] ?? null) !== null;
    }

    /**
     * Clients that currently have a state, as opposed to a remembered clock.
     *
     * @return list<int>
     */
    public function presentClients(): array
    {
        $present = [];

        foreach ($this->clients as $client => $entry) {
            if ($entry['state'] !== null) {
                $present[] = $client;
            }
        }

        return $present;
    }

    public function count(): int
    {
        return count($this->presentClients());
    }

    /**
     * @throws LimitExceeded
     */
    private function assertRoomForAnother(): void
    {
        if ($this->count() >= $this->limits->maxTrackedClients) {
            throw LimitExceeded::elementCount($this->count() + 1, $this->limits->maxTrackedClients, 0);
        }
    }
}
