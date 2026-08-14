<?php

declare(strict_types=1);

use Hemp\Yjs\Id\ClockRange;
use Hemp\Yjs\Id\DeleteSet;

/**
 * Delete sets are the one structure in a Yjs document guaranteed to grow
 * forever, so every operation on them has to keep ranges compact. A method that
 * expanded a range into one entry per clock would work perfectly in a test and
 * exhaust a server on a real document.
 */
$set = fn (array $ranges) => DeleteSet::fromArray(array_map(
    fn (array $clientRanges) => array_map(fn (array $pair) => new ClockRange($pair[0], $pair[1]), $clientRanges),
    $ranges,
));

describe('normalization', function () use ($set) {
    it('coalesces overlapping ranges', function () use ($set) {
        $normalized = $set([1 => [[0, 5], [3, 4]]])->normalized();

        expect($normalized->rangesFor(1))->toHaveCount(1)
            ->and($normalized->rangesFor(1)[0]->clock)->toBe(0)
            ->and($normalized->rangesFor(1)[0]->length)->toBe(7);
    });

    it('coalesces adjacent ranges', function () use ($set) {
        // [0,5) and [5,9) describe one deleted run. Leaving them apart would
        // make two equivalent delete sets compare unequal.
        $normalized = $set([1 => [[0, 5], [5, 4]]])->normalized();

        expect($normalized->rangesFor(1))->toHaveCount(1)
            ->and($normalized->rangesFor(1)[0]->length)->toBe(9);
    });

    it('leaves a real gap alone', function () use ($set) {
        expect($set([1 => [[0, 5], [6, 4]]])->normalized()->rangesFor(1))->toHaveCount(2);
    });

    it('sorts ranges that arrive out of order', function () use ($set) {
        $normalized = $set([1 => [[20, 2], [0, 3], [10, 1]]])->normalized();

        expect(array_map(fn (ClockRange $range) => $range->clock, $normalized->rangesFor(1)))->toBe([0, 10, 20]);
    });

    it('drops empty ranges and the clients left with none', function () use ($set) {
        expect($set([1 => [[0, 0]], 2 => [[5, 1]]])->normalized()->clients())->toBe([2]);
    });

    it('keeps ranges compact rather than expanding them', function () use ($set) {
        $huge = $set([1 => [[0, 1_000_000_000]]])->normalized();

        expect($huge->rangesFor(1))->toHaveCount(1)
            ->and($huge->deletedClockCount())->toBe(1_000_000_000);
    });
});

describe('set operations', function () use ($set) {
    it('unions across clients and coalesces the result', function () use ($set) {
        $union = $set([1 => [[0, 5]]])->union($set([1 => [[5, 5]], 2 => [[0, 1]]]));

        expect($union->rangesFor(1))->toHaveCount(1)
            ->and($union->rangesFor(1)[0]->length)->toBe(10)
            ->and($union->rangesFor(2))->toHaveCount(1);
    });

    it('recognizes a subset', function () use ($set) {
        expect($set([1 => [[2, 3]]])->isSubsetOf($set([1 => [[0, 10]]])))->toBeTrue()
            ->and($set([1 => [[0, 10]]])->isSubsetOf($set([1 => [[2, 3]]])))->toBeFalse();
    });

    it('does not treat a different client as coverage', function () use ($set) {
        expect($set([1 => [[0, 5]]])->isSubsetOf($set([2 => [[0, 5]]])))->toBeFalse();
    });

    it('sees a range split across two entries as covered once coalesced', function () use ($set) {
        expect($set([1 => [[0, 10]]])->isSubsetOf($set([1 => [[0, 4], [4, 6]]])))->toBeTrue();
    });

    it('compares by meaning rather than by shape', function () use ($set) {
        expect($set([1 => [[0, 4], [4, 6]]])->equals($set([1 => [[0, 10]]])))->toBeTrue();
    });

    it('reports membership', function () use ($set) {
        $subject = $set([1 => [[5, 3]]]);

        expect($subject->deletes(1, 5))->toBeTrue()
            ->and($subject->deletes(1, 7))->toBeTrue()
            ->and($subject->deletes(1, 8))->toBeFalse()
            ->and($subject->deletes(2, 5))->toBeFalse();
    });
});

describe('wire fidelity', function () use ($set) {
    it('round-trips through the wire format', function () use ($set) {
        $subject = $set([9 => [[0, 5], [10, 2]], 4 => [[1, 1]]]);

        expect(DeleteSet::decode($subject->encode())->encode())->toBeBytes($subject->encode());
    });

    it('writes clients in descending order, as Yjs does', function () use ($set) {
        $bytes = $set([1 => [[0, 1]], 9 => [[0, 1]]])->encode();

        // count=2, then client 9 before client 1.
        expect($bytes)->toBeBytes("\x02\x09\x01\x00\x01\x01\x01\x00\x01");
    });

    it('preserves a client that declares no deletions', function () {
        // Yjs drops these on read, so its own model would not reproduce the
        // bytes. A relay has to.
        $bytes = "\x01\x07\x00";

        expect(DeleteSet::decode($bytes)->encode())->toBeBytes($bytes);
        expect(DeleteSet::decode($bytes)->isEmpty())->toBeTrue();
        expect(DeleteSet::decode($bytes)->normalized()->clients())->toBe([]);
    });
});
