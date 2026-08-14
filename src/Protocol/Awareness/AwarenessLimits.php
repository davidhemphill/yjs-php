<?php

declare(strict_types=1);

namespace Hemp\Yjs\Protocol\Awareness;

/**
 * Bounds on awareness, which is the easiest part of the protocol to abuse.
 *
 * Awareness is ephemeral, unversioned, and broadcast to every peer in the
 * document. Nothing in the format stops one connection from announcing a
 * million clients with a megabyte of state each, and every byte of it would be
 * fanned out to everyone. Unlike document updates there is no delete set to
 * bound it and no persistence to make it someone else's problem.
 */
final class AwarenessLimits
{
    /**
     * @param  int  $maxClientsPerUpdate  Clients one update may mention.
     * @param  int  $maxStateBytes  JSON payload size for a single client.
     * @param  int  $maxTrackedClients  Clients a store will hold at once.
     */
    public function __construct(
        public int $maxClientsPerUpdate = 512,
        public int $maxStateBytes = 64 * 1024,
        public int $maxTrackedClients = 4096,
    ) {}

    /**
     * How long a client may go unheard from before it is presumed gone.
     *
     * y-protocols uses 30 seconds and refreshes at half that, so a client that
     * is still there will have spoken twice inside the window.
     */
    public const int OUTDATED_TIMEOUT_MS = 30_000;

    public static function strict(): self
    {
        return new self(
            maxClientsPerUpdate: 4,
            maxStateBytes: 64,
            maxTrackedClients: 8,
        );
    }
}
