<?php

declare(strict_types=1);

use Hemp\Yjs\Binary\DecodeLimits;
use Hemp\Yjs\Exception\DecodeException;
use Hemp\Yjs\Exception\InvalidUpdate;
use Hemp\Yjs\Id\ClockRange;
use Hemp\Yjs\Id\DeleteSet;
use Hemp\Yjs\Id\Id;
use Hemp\Yjs\Tests\Support\Fixtures;
use Hemp\Yjs\Update\ClientStructs;
use Hemp\Yjs\Update\SemanticLimits;
use Hemp\Yjs\Update\Update;
use Hemp\Yjs\Wire\Content\Text;
use Hemp\Yjs\Wire\Item;
use Hemp\Yjs\Wire\ParentReference;
use Hemp\Yjs\Wire\Skip;

$item = fn (int $client, int $clock, string $text) => Item::compose(
    new Id($client, $clock),
    null,
    null,
    ParentReference::key('root'),
    null,
    new Text($text),
);

it('accepts every update in the corpus', function (array $case) {
    $update = Update::decode(base64_decode($case['update'], strict: true), DecodeLimits::trusted());

    expect(fn () => $update->validate())->not->toThrow(Throwable::class);
})->with(array_map(fn (array $case) => [$case], Fixtures::cases('updates')));

it('accepts what merge and diff produce', function (array $case) {
    // The structural checks exist for updates we build rather than read, so the
    // ones we build are what they most need to be run against.
    $update = Update::decode(base64_decode($case['update'], strict: true), DecodeLimits::trusted());

    expect(fn () => $update->merge($update)->validate())->not->toThrow(Throwable::class)
        ->and(fn () => $update->diff($update->stateVector())->validate())->not->toThrow(Throwable::class);
})->with(array_map(fn (array $case) => [$case], Fixtures::cases('updates')));

describe('structure', function () use ($item) {
    it('rejects a section whose declared clock is not its first struct', function () use ($item) {
        $update = Update::of([new ClientStructs(1, 5, [$item(1, 0, 'ab')])], DeleteSet::empty());

        expect(fn () => $update->validate())->toThrow(InvalidUpdate::class);
    });

    it('rejects a gap that is not an explicit skip', function () use ($item) {
        // Clocks are reconstructed by accumulation, so an implied hole would be
        // read back as different clocks entirely.
        $update = Update::of(
            [new ClientStructs(1, 0, [$item(1, 0, 'ab'), $item(1, 9, 'cd')])],
            DeleteSet::empty(),
        );

        expect(fn () => $update->validate())->toThrow(InvalidUpdate::class);
    });

    it('accepts the same gap when a skip states it', function () use ($item) {
        $update = Update::of(
            [new ClientStructs(1, 0, [$item(1, 0, 'ab'), new Skip(new Id(1, 2), 7), $item(1, 9, 'cd')])],
            DeleteSet::empty(),
        );

        expect(fn () => $update->validate())->not->toThrow(Throwable::class);
    });

    it('rejects overlapping structs', function () use ($item) {
        $update = Update::of(
            [new ClientStructs(1, 0, [$item(1, 0, 'abcd'), $item(1, 2, 'ef')])],
            DeleteSet::empty(),
        );

        expect(fn () => $update->validate())->toThrow(InvalidUpdate::class);
    });

    it('rejects a client appearing in two sections', function () use ($item) {
        $update = Update::of(
            [new ClientStructs(1, 0, [$item(1, 0, 'a')]), new ClientStructs(1, 1, [$item(1, 1, 'b')])],
            DeleteSet::empty(),
        );

        expect(fn () => $update->validate())->toThrow(InvalidUpdate::class);
    });

    it('rejects a struct belonging to a different client than its section', function () use ($item) {
        $update = Update::of([new ClientStructs(1, 0, [$item(2, 0, 'a')])], DeleteSet::empty());

        expect(fn () => $update->validate())->toThrow(InvalidUpdate::class);
    });
});

describe('limits', function () use ($item) {
    it('rejects too many clients', function () use ($item) {
        $update = Update::of(
            array_map(fn (int $client) => new ClientStructs($client, 0, [$item($client, 0, 'a')]), [1, 2, 3]),
            DeleteSet::empty(),
        );

        expect(fn () => $update->validate(SemanticLimits::strict()))->toThrow(InvalidUpdate::class);
    });

    it('rejects too many structs', function () use ($item) {
        $structs = [];

        for ($clock = 0; $clock < 12; $clock++) {
            $structs[] = $item(1, $clock, 'a');
        }

        $update = Update::of([new ClientStructs(1, 0, $structs)], DeleteSet::empty());

        expect(fn () => $update->validate(SemanticLimits::strict()))->toThrow(InvalidUpdate::class);
    });

    it('rejects a single struct spanning too many clocks', function () {
        $update = Update::of(
            [new ClientStructs(1, 0, [new Skip(new Id(1, 0), 10_000)])],
            DeleteSet::empty(),
        );

        expect(fn () => $update->validate(SemanticLimits::strict()))->toThrow(InvalidUpdate::class);
    });

    it('rejects too many delete ranges', function () {
        $ranges = [];

        for ($clock = 0; $clock < 20; $clock += 2) {
            $ranges[] = new ClockRange($clock, 1);
        }

        $update = Update::of([], DeleteSet::fromArray([1 => $ranges]));

        expect(fn () => $update->validate(SemanticLimits::strict()))->toThrow(InvalidUpdate::class);
    });

    it('accepts the same update under default limits', function () {
        $ranges = [];

        for ($clock = 0; $clock < 20; $clock += 2) {
            $ranges[] = new ClockRange($clock, 1);
        }

        $update = Update::of([], DeleteSet::fromArray([1 => $ranges]));

        expect(fn () => $update->validate())->not->toThrow(Throwable::class);
    });
});

it('reports validation failures as decode failures', function () use ($item) {
    // A server handling a socket should be able to catch everything the peer
    // can do wrong in one place.
    $update = Update::of([new ClientStructs(1, 5, [$item(1, 0, 'ab')])], DeleteSet::empty());

    expect(fn () => $update->validate())->toThrow(DecodeException::class);
});
