<?php

declare(strict_types=1);

use Yjs\Binary\DecodeLimits;
use Yjs\Id\DeleteSet;
use Yjs\Id\StateVector;
use Yjs\Tests\Support\Fixtures;
use Yjs\Update\Update;

/**
 * The update algebra over the committed corpus.
 *
 * The real verification of merge and diff is the differential oracle, which
 * needs Node. These are the properties that can be asserted from the committed
 * fixtures alone, so that the released suite still fails if the algebra breaks.
 */
$decode = fn (string $name) => Update::decode(
    base64_decode(Fixtures::cases('updates')[$name]['update'], strict: true),
    DecodeLimits::trusted(),
);

$cases = array_map(fn (array $case) => [$case], Fixtures::cases('updates'));

describe('state vector', function () use ($cases) {
    it('matches the vector Yjs derives from the same update', function (array $case) {
        $update = Update::decode(base64_decode($case['update'], strict: true), DecodeLimits::trusted());

        expect($update->stateVector()->encode())
            ->toBeBytes(base64_decode($case['stateVector'], strict: true));
    })->with($cases);
});

describe('merge', function () use ($cases, $decode) {
    it('reproduces a single update exactly', function (array $case) {
        // Merging one update rebuilds it from its parts: sections regrouped,
        // skips regenerated from the gaps they mark, info bytes recomputed.
        // Getting the same bytes back means every one of those steps agreed
        // with what was there.
        $bytes = base64_decode($case['update'], strict: true);
        $update = Update::decode($bytes, DecodeLimits::trusted());

        expect($update->merge()->encode())->toBeBytes(base64_decode($case['mergedByYjs'], strict: true));
    })->with($cases);

    it('is idempotent', function (array $case) {
        $update = Update::decode(base64_decode($case['update'], strict: true), DecodeLimits::trusted());

        expect($update->merge($update)->encode())->toBeBytes($update->merge()->encode());
    })->with($cases);

    it('carries every input clock into the result', function () use ($decode) {
        $merged = Update::mergeAll($decode('text-plain'), $decode('map-any'), $decode('multi-client'));

        foreach (['text-plain', 'map-any', 'multi-client'] as $name) {
            foreach ($decode($name)->coverage() as $client => $ranges) {
                foreach ($ranges as $range) {
                    foreach (range($range->clock, $range->end() - 1) as $clock) {
                        expect($merged->contains($decode($name)))->toBeTrue("{$name} at {$client}:{$clock}");
                    }
                }
            }
        }
    });

    it('unions the delete sets', function () use ($decode) {
        $merged = Update::mergeAll($decode('text-deleted'), $decode('deletes-across-clients'));

        expect($decode('text-deleted')->deleteSet->isSubsetOf($merged->deleteSet))->toBeTrue()
            ->and($decode('deletes-across-clients')->deleteSet->isSubsetOf($merged->deleteSet))->toBeTrue();
    });

    it('regenerates a gap as a skip rather than closing over it', function () use ($decode) {
        $merged = $decode('skip-structs')->merge();
        $section = $merged->sections[0];

        expect($section->endClock())->toBe(18)
            ->and($section->contiguousEndClock())->toBe(6)
            ->and($merged->stateVector()->clockFor($section->client))->toBe(6);
    });

    it('returns an empty update when given nothing', function () {
        expect(Update::mergeAll()->isEmpty())->toBeTrue();
    });
});

describe('diff', function () use ($cases, $decode) {
    it('never resends what the peer already has', function (array $case) {
        $update = Update::decode(base64_decode($case['update'], strict: true), DecodeLimits::trusted());
        $known = $update->stateVector();

        $resent = [];

        foreach ($update->diff($known)->sections as $section) {
            foreach ($section->structs as $struct) {
                if ($struct->id()->clock < $known->clockFor($section->client)) {
                    $resent[] = (string) $struct->id();
                }
            }
        }

        expect($resent)->toBe([], "{$case['name']}: resent clocks the peer already had");
    })->with($cases);

    it('sends no structs back when the update has no gaps', function (array $case) {
        // Diffing an update against its own state vector should leave no
        // structs, because the vector says the peer has all of them. The
        // exception is an update with a hole: the vector stops at the hole, so
        // everything past it is still owed.
        //
        // "No structs" rather than "empty", because the delete set always
        // travels — see the test below for why it cannot be filtered.
        $update = Update::decode(base64_decode($case['update'], strict: true), DecodeLimits::trusted());

        $hasGap = array_filter(
            $update->sections,
            fn ($section) => $section->contiguousEndClock() !== $section->endClock(),
        ) !== [];

        expect($update->diff($update->stateVector())->structCount() === 0)->toBe(! $hasGap, $case['name']);
    })->with($cases);

    it('sends the content beyond a gap, which no state vector can describe', function () use ($decode) {
        $update = $decode('skip-structs');

        // Item(0,6) Skip(6,7) Item(13,5): the vector can only claim 6, so the
        // structs past the hole come back however much the peer already has.
        $remaining = $update->diff($update->stateVector());

        expect($remaining->structCount())->toBe(1)
            ->and($remaining->structs()[0]->id()->clock)->toBe(13)
            ->and($remaining->structs()[0]->length())->toBe(5);
    });

    it('returns everything when the peer has nothing', function (array $case) {
        $update = Update::decode(base64_decode($case['update'], strict: true), DecodeLimits::trusted());

        expect($update->diff(StateVector::empty())->encode())->toBeBytes($update->encode());
    })->with($cases);

    it('carries the delete set through unfiltered', function () use ($decode) {
        // A state vector counts structs, and a deletion is not a struct, so
        // there is no way to know which tombstones the peer holds.
        $update = $decode('deletes-across-clients');

        expect($update->diff($update->stateVector())->deleteSet->encode())
            ->toBeBytes($update->deleteSet->encode());
    });

    it('sends only what is past the peer clock', function () use ($decode) {
        $update = $decode('text-plain');
        $client = $update->sections[0]->client;

        $partial = $update->diff(StateVector::empty()->with($client, 3));

        expect($partial->sections[0]->clock)->toBe(3);
    });
});

describe('containment', function () use ($cases, $decode) {
    it('contains itself', function (array $case) {
        $update = Update::decode(base64_decode($case['update'], strict: true), DecodeLimits::trusted());

        expect($update->contains($update))->toBeTrue();
    })->with($cases);

    it('recognizes an update that introduces nothing new', function () use ($decode) {
        $full = $decode('text-plain');
        $prefix = $full->diff(StateVector::empty());

        expect($full->contains($prefix))->toBeTrue();
    });

    it('recognizes an update that introduces state', function () use ($decode) {
        // The read-only decision: an empty resident state cannot already
        // account for a client's content, so this update would be introducing
        // document state.
        expect(Update::empty()->contains($decode('text-plain')))->toBeFalse();
    });

    it('does not treat a different client as coverage', function () use ($decode) {
        expect($decode('text-plain')->contains($decode('map-any')))->toBeFalse();
    });

    it('ignores skips on both sides', function () use ($decode) {
        // A skip asserts a hole in the sender's knowledge, not content, so it
        // neither needs covering nor covers anything.
        $withGap = $decode('skip-structs');

        expect($withGap->contains($withGap))->toBeTrue();
    });

    it('requires the delete set to be covered too', function () use ($decode) {
        $deleted = $decode('text-deleted');
        $withoutDeletes = Update::of($deleted->sections, DeleteSet::empty());

        expect($withoutDeletes->contains($deleted))->toBeFalse()
            ->and($deleted->contains($withoutDeletes))->toBeTrue();
    });
});
