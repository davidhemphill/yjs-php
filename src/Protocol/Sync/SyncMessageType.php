<?php

declare(strict_types=1);

namespace Hemp\Yjs\Protocol\Sync;

/**
 * The three y-protocols sync messages.
 *
 * The numbers are wire constants. A sync is two peers each sending Step1 and
 * answering the other's with Step2, after which they send Update to each other
 * for as long as the connection lasts.
 */
enum SyncMessageType: int
{
    /** "Here is what I have" — carries a state vector. */
    case Step1 = 0;

    /** "Here is what you were missing" — carries an update, in reply to Step1. */
    case Step2 = 1;

    /** "Here is something new" — carries an update, unprompted. */
    case Update = 2;
}
