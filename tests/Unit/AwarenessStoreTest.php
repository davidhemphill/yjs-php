<?php

declare(strict_types=1);

use Hemp\Yjs\Exception\DecodeException;
use Hemp\Yjs\Exception\LimitExceeded;
use Hemp\Yjs\Protocol\Awareness\AwarenessEntry;
use Hemp\Yjs\Protocol\Awareness\AwarenessLimits;
use Hemp\Yjs\Protocol\Awareness\AwarenessStore;
use Hemp\Yjs\Protocol\Awareness\AwarenessUpdate;

$update = fn (array ...$entries) => new AwarenessUpdate(
    array_map(fn (array $entry) => new AwarenessEntry(...$entry), $entries),
);

describe('acceptance', function () use ($update) {
    it('accepts a higher clock', function () use ($update) {
        $store = new AwarenessStore;

        $store->apply($update([1, 1, '{"a":1}']), now: 1000);
        $change = $store->apply($update([1, 2, '{"a":2}']), now: 1000);

        expect($change->updated)->toBe([1])
            ->and($store->stateFor(1))->toBe('{"a":2}');
    });

    it('ignores a lower clock', function () use ($update) {
        $store = new AwarenessStore;

        $store->apply($update([1, 5, '{"a":5}']), now: 1000);
        $change = $store->apply($update([1, 2, '{"a":2}']), now: 1000);

        expect($change->isEmpty())->toBeTrue()
            ->and($store->stateFor(1))->toBe('{"a":5}');
    });

    it('ignores an identical clock carrying a state', function () use ($update) {
        $store = new AwarenessStore;

        $store->apply($update([1, 5, '{"a":5}']), now: 1000);
        $change = $store->apply($update([1, 5, '{"a":9}']), now: 1000);

        expect($change->isEmpty())->toBeTrue()
            ->and($store->stateFor(1))->toBe('{"a":5}');
    });

    it('accepts a removal at the same clock', function () use ($update) {
        // The one exception, and the reason it exists: whoever noticed a client
        // leave has no clock of its own to announce it with, so a departure is
        // allowed to land on the clock the client was last seen at.
        $store = new AwarenessStore;

        $store->apply($update([1, 5, '{"a":5}']), now: 1000);
        $change = $store->apply($update([1, 5, null]), now: 1000);

        expect($change->removed)->toBe([1])
            ->and($store->knows(1))->toBeFalse();
    });

    it('reports a repeated state as a refresh, not a change', function () use ($update) {
        $store = new AwarenessStore;

        $store->apply($update([1, 1, '{"a":1}']), now: 1000);
        $change = $store->apply($update([1, 2, '{"a":1}']), now: 1000);

        // The clock advanced but the presence did not. That is the client's
        // heartbeat: y-protocols fires its `update` event for it and a server
        // rebroadcasts it, or every idle cursor expires after thirty seconds.
        expect($change->refreshed)->toBe([1])
            ->and($change->updated)->toBe([])
            ->and($change->added)->toBe([])
            ->and($store->clockFor(1))->toBe(2);
    });

    it('does not report a refresh for a state it rejected', function () use ($update) {
        $store = new AwarenessStore;

        $store->apply($update([1, 5, '{"a":1}']), now: 1000);
        $change = $store->apply($update([1, 5, '{"a":1}']), now: 1000);

        // Same clock, same state: y-protocols accepts nothing here and emits
        // nothing, so neither does the store. This is what keeps a peer's
        // restatement of someone else's presence from echoing forever.
        expect($change->isEmpty())->toBeTrue();
    });

    it('reports a new client as added', function () use ($update) {
        $store = new AwarenessStore;

        expect($store->apply($update([7, 1, '{}']), now: 1000)->added)->toBe([7]);
    });

    it('remembers the clock of a departed client', function () use ($update) {
        // Forgetting it would let a stale message from that client reinstate
        // them, since any clock beats no clock.
        $store = new AwarenessStore;

        $store->apply($update([1, 5, '{"a":5}']), now: 1000);
        $store->apply($update([1, 6, null]), now: 1000);

        expect($store->clockFor(1))->toBe(6)
            ->and($store->apply($update([1, 3, '{"a":3}']), now: 1000)->isEmpty())->toBeTrue()
            ->and($store->knows(1))->toBeFalse();
    });
});

describe('expiry', function () use ($update) {
    it('drops a client that has gone quiet', function () use ($update) {
        // Awareness has no reliable disconnect signal — a dropped connection
        // produces nothing at all — so presence has to be forgotten on a timer.
        $store = new AwarenessStore;
        $store->apply($update([1, 1, '{}']), now: 1_000);

        expect($store->expire(now: 1_000 + AwarenessLimits::OUTDATED_TIMEOUT_MS)->removed)->toBe([1])
            ->and($store->knows(1))->toBeFalse();
    });

    it('keeps a client that spoke recently', function () use ($update) {
        $store = new AwarenessStore;
        $store->apply($update([1, 1, '{}']), now: 1_000);

        expect($store->expire(now: 1_000 + AwarenessLimits::OUTDATED_TIMEOUT_MS - 1)->isEmpty())->toBeTrue()
            ->and($store->knows(1))->toBeTrue();
    });

    it('raises the clock when expiring, so the removal wins downstream', function () use ($update) {
        $store = new AwarenessStore;
        $store->apply($update([1, 4, '{}']), now: 1_000);
        $store->expire(now: 100_000);

        expect($store->clockFor(1))->toBe(5);
    });
});

describe('broadcasting', function () use ($update) {
    it('describes everyone present', function () use ($update) {
        $store = new AwarenessStore;
        $store->apply($update([1, 1, '{"a":1}'], [2, 1, '{"b":2}']), now: 1000);

        $announcement = $store->updateFor();

        expect($announcement->entries)->toHaveCount(2)
            ->and($announcement->clients())->toBe([1, 2]);
    });

    it('leaves out clients that are only a remembered clock', function () use ($update) {
        $store = new AwarenessStore;
        $store->apply($update([1, 1, '{"a":1}'], [2, 1, null]), now: 1000);

        expect($store->updateFor()->clients())->toBe([1]);
    });

    it('announces a departure one clock past the last sighting', function () use ($update) {
        $store = new AwarenessStore;
        $store->apply($update([1, 4, '{"a":1}']), now: 1000);

        $removal = $store->removalFor([1]);

        expect($removal->entries[0]->isRemoval())->toBeTrue()
            ->and($removal->entries[0]->clock)->toBe(5);
    });

    it('forgets a client entirely when its connection closes', function () use ($update) {
        $store = new AwarenessStore;
        $store->apply($update([1, 4, '{"a":1}']), now: 1000);

        expect($store->forget([1])->removed)->toBe([1])
            ->and($store->clockFor(1))->toBe(0);
    });

    it('round-trips its own announcement through the wire', function () use ($update) {
        $store = new AwarenessStore;
        $store->apply($update([1, 1, '{"a":1}'], [2, 3, '{"b":"日本"}']), now: 1000);

        $bytes = $store->updateFor()->encode();
        $received = new AwarenessStore;
        $received->apply(AwarenessUpdate::decode($bytes), now: 2000);

        expect($received->stateFor(1))->toBe('{"a":1}')
            ->and($received->stateFor(2))->toBe('{"b":"日本"}')
            ->and($received->clockFor(2))->toBe(3);
    });
});

describe('bounds', function () use ($update) {
    it('rejects an update mentioning too many clients', function () {
        $entries = [];

        for ($client = 1; $client <= 20; $client++) {
            $entries[] = new AwarenessEntry($client, 1, '{}');
        }

        $bytes = (new AwarenessUpdate($entries))->encode();

        expect(fn () => AwarenessUpdate::decode($bytes, AwarenessLimits::strict()))
            ->toThrow(LimitExceeded::class);
    });

    it('rejects a state payload that is too large', function () {
        $bytes = (new AwarenessUpdate([new AwarenessEntry(1, 1, str_repeat('x', 500))]))->encode();

        expect(fn () => AwarenessUpdate::decode($bytes, AwarenessLimits::strict()))
            ->toThrow(LimitExceeded::class);
    });

    it('refuses to track more clients than allowed', function () use ($update) {
        // Awareness is broadcast to every peer and has no delete set to bound
        // it, so an unbounded store is a direct amplification path.
        $store = new AwarenessStore(AwarenessLimits::strict());

        $entries = [];

        for ($client = 1; $client <= 20; $client++) {
            $entries[] = [$client, 1, '{}'];
        }

        expect(fn () => $store->apply($update(...$entries), now: 1000))
            ->toThrow(LimitExceeded::class);
    });

    it('rejects a declared client count larger than the frame', function () {
        // Claims a million clients in four bytes.
        expect(fn () => AwarenessUpdate::decode("\xC0\x84\x3D"))
            ->toThrow(DecodeException::class);
    });
});
