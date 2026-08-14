<?php

declare(strict_types=1);

use Hemp\Yjs\Binary\DecodeLimits;
use Hemp\Yjs\Id\StateVector;
use Hemp\Yjs\Tests\Support\Fixtures;
use Hemp\Yjs\Update\Update;
use Hemp\Yjs\Wire\Content\SubDocument;
use Hemp\Yjs\Wire\Gc;
use Hemp\Yjs\Wire\Item;
use Hemp\Yjs\Wire\Skip;

/**
 * Whole Yjs V1 updates, checked against updates built by the real Yjs.
 *
 * The structural assertions matter as much as the byte comparison. Bytes alone
 * would pass if we decoded an update into the wrong shape and made the same
 * mistake on the way back out — and that failure mode is exactly the one that
 * would survive to Phase 3 and corrupt a merge.
 */
$cases = array_map(fn (array $case) => [$case], Fixtures::cases('updates'));

/** The PHP class each Yjs struct name corresponds to. */
$structClasses = ['Item' => Item::class, 'GC' => Gc::class, 'Skip' => Skip::class];

it('decodes into the structure Yjs reads', function (array $case) use ($structClasses) {
    $update = Update::decode(base64_decode($case['update'], strict: true), DecodeLimits::trusted());

    $structs = $update->structs();

    expect($structs)->toHaveCount(count($case['structs']), "{$case['name']}: struct count");

    foreach ($case['structs'] as $index => $expected) {
        $struct = $structs[$index];
        $where = "{$case['name']} struct #{$index}";

        expect($struct)->toBeInstanceOf($structClasses[$expected['kind']], "{$where}: kind");
        expect($struct->id()->client)->toBe($expected['client'], "{$where}: client");
        expect($struct->id()->clock)->toBe($expected['clock'], "{$where}: clock");

        // The length is what advances the clock to the next struct, so a wrong
        // one here would misplace everything after it.
        expect($struct->length())->toBe($expected['length'], "{$where}: length");

        if ($expected['contentRef'] !== null) {
            expect($struct)->toBeInstanceOf(Item::class, "{$where}: expected an Item");
            expect($struct->content->ref())->toBe($expected['contentRef'], "{$where}: content ref");
            expect($struct->contentRef())->toBe($expected['contentRef'], "{$where}: info byte content ref");
        }
    }
})->with($cases);

it('re-encodes to the identical bytes', function (array $case) {
    $bytes = base64_decode($case['update'], strict: true);

    expect(Update::decode($bytes, DecodeLimits::trusted())->encode())->toBeBytes($bytes);
})->with($cases);

/**
 * Agreement with Yjs's own update-level round trip.
 *
 * For every current fixture this is the same bytes as the input, because
 * merging a single update is a no-op in Yjs. It is asserted separately anyway
 * because `mergedByYjs` is the oracle Phase 3 needs — once merges combine two
 * updates the result will differ from either input, and having the comparison
 * already wired means the merge work starts with a real reference rather than
 * a fresh assumption.
 */
it('agrees with Yjs at the update level', function (array $case) {
    $bytes = base64_decode($case['update'], strict: true);

    expect(Update::decode($bytes, DecodeLimits::trusted())->encode())
        ->toBeBytes(base64_decode($case['mergedByYjs'], strict: true));
})->with($cases);

/**
 * Yjs has two round trips over an update and they are not equivalent: through a
 * live `Doc`, which rebuilds content from the materialized document, or through
 * `mergeUpdates`, which works on the update itself. This library is the second
 * kind, and this is the fixture that can tell the difference.
 */
it('follows the update-level path where the two Yjs paths disagree', function () {
    $case = Fixtures::cases('updates')['subdocument-foreign-opts'];

    $updateLevel = base64_decode($case['mergedByYjs'], strict: true);
    $liveDocument = base64_decode($case['viaLiveDocument'], strict: true);

    // Guard the premise: if these ever stop differing, this test has quietly
    // stopped discriminating and would pass no matter which path we copied.
    expect(bin2hex($updateLevel))->not->toBe(bin2hex($liveDocument));

    $update = Update::decode(base64_decode($case['update'], strict: true), DecodeLimits::trusted());

    expect($update->encode())->toBeBytes($updateLevel);
    expect(bin2hex($update->encode()))->not->toBe(bin2hex($liveDocument));

    // The live-document path drops these; the update-level path keeps them.
    $content = $update->structs()[0]->content;

    expect($content)->toBeInstanceOf(SubDocument::class)
        ->and($content->guid)->toBe('guid-1')
        ->and(get_object_vars($content->options))->toHaveKeys(['shouldLoad', 'extraKey', 'gc']);
});

it('matches both Yjs paths for every update Yjs originated', function (array $case) {
    // ContentDoc normalizes its options when the content is constructed, so
    // anything Yjs produces is already normalized before it reaches the wire and
    // the two paths cannot come apart. Only a foreign update separates them.
    if ($case['name'] === 'subdocument-foreign-opts') {
        expect($case['mergedByYjs'])->not->toBe($case['viaLiveDocument']);

        return;
    }

    expect($case['mergedByYjs'])->toBe($case['viaLiveDocument'], "{$case['name']}: Yjs paths disagree");
})->with($cases);

it('decodes the delete set Yjs reads', function (array $case) {
    $update = Update::decode(base64_decode($case['update'], strict: true), DecodeLimits::trusted());

    $actual = [];

    foreach ($update->deleteSet->clients() as $client) {
        $actual[] = [
            'client' => $client,
            'ranges' => array_map(
                fn ($range) => ['clock' => $range->clock, 'length' => $range->length],
                $update->deleteSet->rangesFor($client),
            ),
        ];
    }

    expect($actual)->toBe($case['deleteSet']);
})->with($cases);

it('survives a decode, encode, decode cycle unchanged', function (array $case) {
    // The metamorphic property: whatever normalization the first pass performs,
    // a second pass must be a no-op. A representation that kept drifting would
    // still pass a single round trip.
    $bytes = base64_decode($case['update'], strict: true);

    $once = Update::decode($bytes, DecodeLimits::trusted())->encode();
    $twice = Update::decode($once, DecodeLimits::trusted())->encode();

    expect($twice)->toBeBytes($once);
})->with($cases);

it('reads the state vector Yjs derives from the same update', function (array $case) {
    // Phase 3 computes this from the update itself; for now the assertion is
    // that our state vector codec agrees with Yjs on the same bytes.
    $vector = StateVector::decode(base64_decode($case['stateVector'], strict: true));

    expect($vector->encode())->toBeBytes(base64_decode($case['stateVector'], strict: true));

    // A state vector describes a prefix, so the clock it carries is the end of
    // the client's contiguous run — not the end of its last struct. The two
    // differ exactly when the update has a gap, which is why the skip-structs
    // fixture is the one that pins this down.
    $update = Update::decode(base64_decode($case['update'], strict: true), DecodeLimits::trusted());

    foreach ($update->sections as $section) {
        expect($vector->clockFor($section->client))
            ->toBe($section->contiguousEndClock(), "{$case['name']}: client {$section->client}");
    }
})->with($cases);

it('stops the contiguous clock at a gap', function () {
    $skipCase = Fixtures::cases('updates')['skip-structs'];
    $update = Update::decode(base64_decode($skipCase['update'], strict: true), DecodeLimits::trusted());

    $section = $update->sections[0];

    // Item(0,6) Skip(6,7) Item(13,5): the structs reach clock 18, but only the
    // first six are contiguous, so that is all a state vector may claim.
    expect($section->endClock())->toBe(18)
        ->and($section->contiguousEndClock())->toBe(6);
});

it('covers every struct kind and content reference in Profile 1', function () {
    $contentRefs = [];
    $kinds = [];

    foreach (Fixtures::cases('updates') as $case) {
        foreach ($case['structs'] as $struct) {
            $kinds[$struct['kind']] = true;

            if ($struct['contentRef'] !== null) {
                $contentRefs[$struct['contentRef']] = true;
            }
        }
    }

    expect(array_keys($kinds))->toEqualCanonicalizing(['Item', 'GC', 'Skip']);
    expect(array_keys($contentRefs))->toEqualCanonicalizing(range(1, 9));
});
