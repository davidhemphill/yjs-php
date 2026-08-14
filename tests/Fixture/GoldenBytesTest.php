<?php

declare(strict_types=1);

use Hemp\Yjs\Binary\DecodeLimits;
use Hemp\Yjs\Binary\Decoder;
use Hemp\Yjs\Binary\Encoder;
use Hemp\Yjs\Tests\Support\Fixtures;
use Hemp\Yjs\Tests\Support\PrimitiveGroups;

/**
 * Every lib0 primitive, checked against bytes produced by the real lib0 build.
 *
 * Three assertions per fixture, because each catches a different kind of wrong:
 * encoding proves we write what lib0 writes, decoding proves we read what lib0
 * wrote, and the round trip proves the two are not wrong in the same direction.
 */

/**
 * Wrap each case so Pest passes it as one argument rather than spreading it,
 * and key the dataset by the fixture's own name so a failure names the value.
 */
$dataset = fn (string $group): array => array_map(
    fn (array $case) => [$case],
    Fixtures::cases($group),
);

foreach (PrimitiveGroups::all() as $group => $writer) {
    describe($group, function () use ($writer, $group, $dataset) {
        it('encodes to the lib0 golden bytes', function (array $case) use ($writer, $group) {
            $encoder = new Encoder;
            ($writer['encode'])($encoder, PrimitiveGroups::valueFor($group, $case));

            expect($encoder->toBytes())->toBeBytes(Fixtures::bytes($case));
        })->with($dataset($group));

        it('decodes the lib0 golden bytes', function (array $case) use ($writer, $group) {
            $decoder = new Decoder(Fixtures::bytes($case), DecodeLimits::trusted());

            $decoded = ($writer['decode'])($decoder);

            expect($decoded)->toBeSameValueAs(PrimitiveGroups::valueFor($group, $case));
            expect($decoder->remaining())->toBe(0, 'The reader should consume the whole fixture.');
        })->with($dataset($group));

        it('round-trips through PHP alone', function (array $case) use ($writer, $group) {
            $value = PrimitiveGroups::valueFor($group, $case);

            $encoder = new Encoder;
            ($writer['encode'])($encoder, $value);

            $decoded = ($writer['decode'])(new Decoder($encoder->toBytes(), DecodeLimits::trusted()));

            expect($decoded)->toBeSameValueAs($value);
        })->with($dataset($group));
    });
}

it('covers every committed fixture group', function () {
    $committed = array_map(
        fn (string $path) => basename($path, '.json'),
        glob(dirname(Fixtures::path('manifest')).'/*.json'),
    );

    // manifest carries package versions; the utf16 and update groups have their
    // own tests. Everything else must be driven by a writer above, so a new
    // fixture file cannot be added without also being exercised somewhere.
    $exercisedElsewhere = ['manifest', 'utf16', 'utf16-split', 'updates', 'protocol'];

    $expected = array_values(array_diff($committed, $exercisedElsewhere));

    expect(array_keys(PrimitiveGroups::all()))->toEqualCanonicalizing($expected);
});
