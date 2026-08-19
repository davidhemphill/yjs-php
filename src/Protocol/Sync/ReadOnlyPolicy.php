<?php

declare(strict_types=1);

namespace Hemp\Yjs\Protocol\Sync;

use Hemp\Yjs\Binary\DecodeLimits;
use Hemp\Yjs\Exception\DecodeException;
use Hemp\Yjs\Update\Update;

/**
 * Decides what a read-only session is allowed to send.
 *
 * A client with no write permission still performs a full sync handshake — it
 * has to, or it would never receive the document — and a full handshake means
 * answering our SyncStep1 with a SyncStep2. So a read-only peer *will* send
 * updates, and refusing all of them would break the handshake it is entitled
 * to complete.
 *
 * For the step two that answers our question, the distinction that matters is
 * not whether an update arrived but whether it would change anything: an
 * update we already account for costs nothing to accept. An unprompted Update
 * is different — nothing in the handshake obliged the peer to send one, so it
 * is refused without inspection, exactly as Hocuspocus refuses it.
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

        // An unprompted update is refused without looking inside it. The
        // step two below gets a containment check because the peer had to
        // send it — it answers our own step one — but nothing obliged anyone
        // to send an Update. Hocuspocus draws the same line: readSyncStep2
        // checks the snapshot, messageYjsUpdate answers false outright.
        if ($message instanceof SyncUpdate) {
            return SyncAdmission::IntroducesState;
        }

        if (! $message instanceof SyncStep2) {
            return SyncAdmission::Redundant;
        }

        $update = $message->update($limits);

        if ($update->isEmpty()) {
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
