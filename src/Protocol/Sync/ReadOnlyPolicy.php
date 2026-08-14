<?php

declare(strict_types=1);

namespace Yjs\Protocol\Sync;

use Yjs\Binary\DecodeLimits;
use Yjs\Exception\DecodeException;
use Yjs\Update\Update;

/**
 * Decides what a read-only session is allowed to send.
 *
 * A client with no write permission still performs a full sync handshake — it
 * has to, or it would never receive the document — and a full handshake means
 * answering our SyncStep1 with a SyncStep2. So a read-only peer *will* send
 * updates, and refusing all of them would break the handshake it is entitled
 * to complete.
 *
 * The distinction that matters is not whether an update arrived but whether it
 * would change anything. An update we already account for costs nothing to
 * accept and nothing to ignore; an update carrying state we do not have is the
 * peer writing to a document it may only read.
 *
 * This is a decision, not an enforcement point. Nothing here mutates state or
 * talks to a socket — the session applies the verdict, which keeps the rule
 * testable without a server around it.
 */
final class ReadOnlyPolicy
{
    private function __construct() {}

    /**
     * @param  Update  $resident  The state the server currently holds.
     *
     * @throws DecodeException When the message carries an undecodable update.
     */
    public static function admit(
        SyncMessage $message,
        Update $resident,
        ?DecodeLimits $limits = null,
    ): SyncAdmission {
        // Asking what we have asserts nothing, so it is always allowed.
        if ($message instanceof SyncStep1) {
            return SyncAdmission::Allowed;
        }

        $update = match (true) {
            $message instanceof SyncStep2 => $message->update($limits),
            $message instanceof SyncUpdate => $message->update($limits),
            default => null,
        };

        if ($update === null || $update->isEmpty()) {
            return SyncAdmission::Redundant;
        }

        return $resident->contains($update)
            ? SyncAdmission::Redundant
            : SyncAdmission::IntroducesState;
    }

    /**
     * Whether the sync-status acknowledgement should be positive.
     *
     * Both allowed and redundant messages get a positive acknowledgement: from
     * the client's point of view its state is on the server either way, and
     * only an attempt to introduce state gets a negative answer.
     */
    public static function acknowledgesPositively(SyncAdmission $admission): bool
    {
        return $admission !== SyncAdmission::IntroducesState;
    }
}
