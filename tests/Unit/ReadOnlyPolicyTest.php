<?php

declare(strict_types=1);

use Hemp\Yjs\Binary\DecodeLimits;
use Hemp\Yjs\Exception\DecodeException;
use Hemp\Yjs\Exception\MalformedInput;
use Hemp\Yjs\Id\StateVector;
use Hemp\Yjs\Protocol\Sync\ReadOnlyPolicy;
use Hemp\Yjs\Protocol\Sync\SyncAdmission;
use Hemp\Yjs\Protocol\Sync\SyncMessageReader;
use Hemp\Yjs\Protocol\Sync\SyncStep1;
use Hemp\Yjs\Protocol\Sync\SyncStep2;
use Hemp\Yjs\Protocol\Sync\SyncUpdate;
use Hemp\Yjs\Tests\Support\Fixtures;
use Hemp\Yjs\Update\Update;

/**
 * The decision table for a session that may read a document but not write it.
 *
 * The awkward part of read-only is that such a client still has to complete a
 * sync handshake, and a handshake means answering our SyncStep1 with a
 * SyncStep2. So a read-only peer will send updates as a matter of course, and
 * refusing all of them would break the very exchange it is entitled to.
 */
$resident = fn () => Update::decode(
    base64_decode(Fixtures::cases('updates')['text-plain']['update'], strict: true),
    DecodeLimits::trusted(),
);

it('always allows a step1', function () use ($resident) {
    // Asking what the server has asserts nothing about the document.
    $message = new SyncStep1(StateVector::empty());

    expect(ReadOnlyPolicy::admit($message, $resident()))->toBe(SyncAdmission::Allowed);
});

it('treats an empty step2 as redundant', function () use ($resident) {
    $message = SyncStep2::of(Update::empty());

    expect(ReadOnlyPolicy::admit($message, $resident(), DecodeLimits::trusted()))
        ->toBe(SyncAdmission::Redundant);
});

it('treats a step2 the server already has as redundant', function () use ($resident) {
    // The common case: the client echoes back state it received from us.
    $message = SyncStep2::of($resident());

    expect(ReadOnlyPolicy::admit($message, $resident(), DecodeLimits::trusted()))
        ->toBe(SyncAdmission::Redundant);
});

it('refuses a step2 that introduces state', function () use ($resident) {
    $other = Update::decode(
        base64_decode(Fixtures::cases('updates')['map-any']['update'], strict: true),
        DecodeLimits::trusted(),
    );

    expect(ReadOnlyPolicy::admit(SyncStep2::of($other), $resident(), DecodeLimits::trusted()))
        ->toBe(SyncAdmission::IntroducesState);
});

it('refuses an unprompted update that introduces state', function () use ($resident) {
    $other = Update::decode(
        base64_decode(Fixtures::cases('updates')['map-any']['update'], strict: true),
        DecodeLimits::trusted(),
    );

    expect(ReadOnlyPolicy::admit(SyncUpdate::of($other), $resident(), DecodeLimits::trusted()))
        ->toBe(SyncAdmission::IntroducesState);
});

it('refuses a partial extension of state the server already has', function () use ($resident) {
    // Sharing a client with the resident state is not enough; the question is
    // whether any clock is new.
    $extended = Update::decode(
        base64_decode(Fixtures::cases('updates')['text-formatted']['update'], strict: true),
        DecodeLimits::trusted(),
    );

    expect(ReadOnlyPolicy::admit(SyncStep2::of($extended), $resident(), DecodeLimits::trusted()))
        ->toBe(SyncAdmission::IntroducesState);
});

it('acknowledges positively for anything but an attempt to write', function () {
    expect(ReadOnlyPolicy::acknowledgesPositively(SyncAdmission::Allowed))->toBeTrue()
        ->and(ReadOnlyPolicy::acknowledgesPositively(SyncAdmission::Redundant))->toBeTrue()
        ->and(ReadOnlyPolicy::acknowledgesPositively(SyncAdmission::IntroducesState))->toBeFalse();
});

it('rejects a sync message type y-protocols does not define', function () {
    expect(fn () => SyncMessageReader::decode("\x09\x00"))->toThrow(MalformedInput::class);
});

it('rejects trailing bytes after a complete message', function () {
    $bytes = (new SyncStep1(StateVector::empty()))->encode();

    expect(fn () => SyncMessageReader::decode($bytes."\xFF"))->toThrow(MalformedInput::class);
});

it('fails bounded on every truncation of a real frame', function (int $index) {
    $bytes = base64_decode(Fixtures::load('protocol')['sync'][$index]['bytes'], strict: true);

    for ($length = 0; $length < strlen($bytes); $length++) {
        try {
            SyncMessageReader::decodeAll(substr($bytes, 0, $length), DecodeLimits::trusted());
        } catch (DecodeException) {
            continue;
        } catch (Throwable $unexpected) {
            throw new RuntimeException(sprintf(
                'Truncation to %d bytes leaked a %s: %s',
                $length,
                $unexpected::class,
                $unexpected->getMessage(),
            ), previous: $unexpected);
        }
    }

    expect(true)->toBeTrue();
})->with([0, 1, 2, 3, 4, 5]);
