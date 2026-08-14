<?php

declare(strict_types=1);

namespace Hemp\Yjs\Protocol\Sync;

/**
 * What a read-only session may do with an inbound sync message.
 */
enum SyncAdmission
{
    /** Process it normally. It asks for state rather than asserting any. */
    case Allowed;

    /**
     * Accept and acknowledge, but merge nothing.
     *
     * The peer sent state we already have. Refusing would be wrong — the client
     * did nothing prohibited and a negative acknowledgement would make it retry
     * forever — but there is also nothing to apply.
     */
    case Redundant;

    /**
     * Refuse. Do not merge, do not broadcast, acknowledge negatively.
     *
     * The peer tried to add document state it is not permitted to add.
     */
    case IntroducesState;
}
