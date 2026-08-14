<?php

declare(strict_types=1);

use Hemp\Yjs\Binary\AnyValue\BigInt;
use Hemp\Yjs\Binary\AnyValue\Bytes;
use Hemp\Yjs\Binary\AnyValue\Undefined;
use Hemp\Yjs\Binary\DecodeLimits;
use Hemp\Yjs\Binary\Decoder;
use Hemp\Yjs\Binary\Encoder;
use Hemp\Yjs\Debug\CanonicalJson;
use Hemp\Yjs\Tests\Support\RandomValues;

it('renders each value distinguishably', function (mixed $value, string $expected) {
    expect(CanonicalJson::encode($value))->toBe($expected);
})->with([
    'null' => [null, 'null'],
    'undefined' => [Undefined::instance(), '{"$undefined":true}'],
    'true' => [true, 'true'],
    'int' => [5, '5'],
    'whole float' => [5.0, '{"$float":5}'],
    'fractional float' => [0.5, '{"$float":0.5}'],
    'nan' => [NAN, '{"$float":"NaN"}'],
    'infinity' => [INF, '{"$float":"Infinity"}'],
    'negative infinity' => [-INF, '{"$float":"-Infinity"}'],
    'negative zero' => [-0.0, '{"$float":"-0"}'],
    'positive zero' => [0.0, '{"$float":0}'],
    'string' => ['hi', '"hi"'],
    'emoji' => ['😀', '"😀"'],
    'bigint' => [new BigInt(-1), '{"$bigint":"-1"}'],
    'bytes' => [new Bytes("\x00\xFF"), '{"$bytes":"00ff"}'],
    'empty list' => [[], '[]'],
    'list' => [[1, 'two'], '[1,"two"]'],
    'empty object' => [new stdClass, '{"$object":[]}'],
]);

/**
 * The pairs that plain `json_encode` would collapse. Each of these is a real
 * distinction on the wire, so a debug rendering that hid it would send someone
 * looking in the wrong place.
 */
it('keeps apart the values plain JSON would collapse', function (mixed $left, mixed $right) {
    expect(CanonicalJson::encode($left))->not->toBe(CanonicalJson::encode($right));
})->with([
    'null against undefined' => [null, Undefined::instance()],
    'int against whole float' => [5, 5.0],
    'zero against negative zero' => [0.0, -0.0],
    'int against bigint' => [1, new BigInt(1)],
    'string against bytes' => ['ab', new Bytes('ab')],
    'empty list against empty object' => [[], new stdClass],
]);

it('preserves object key order', function () {
    $ordered = new stdClass;
    $ordered->b = 1;
    $ordered->a = 2;

    $reversed = new stdClass;
    $reversed->a = 2;
    $reversed->b = 1;

    expect(CanonicalJson::encode($ordered))->toBe('{"$object":[["b",1],["a",2]]}')
        ->and(CanonicalJson::encode($ordered))->not->toBe(CanonicalJson::encode($reversed));
});

it('cannot be confused by a key that looks like a tag', function () {
    $lookalike = new stdClass;
    $lookalike->{'$bytes'} = 'ff';

    expect(CanonicalJson::encode($lookalike))
        ->toBe('{"$object":[["$bytes","ff"]]}')
        ->not->toBe(CanonicalJson::encode(new Bytes("\xFF")));
});

/**
 * The property that makes this useful as a debugging tool: two values render
 * the same rendering exactly when they encode to the same bytes.
 */
it('renders identically only when the bytes are identical', function (int $seed) {
    mt_srand($seed);

    $renderings = [];

    for ($iteration = 0; $iteration < 300; $iteration++) {
        $value = RandomValues::any();
        $bytes = (new Encoder)->writeAny($value)->toBytes();

        // Compare against what the decoder produces, since that is the value a
        // failure would actually be inspecting.
        $decoded = (new Decoder($bytes, DecodeLimits::trusted()))->readAny();
        $rendering = CanonicalJson::encode($decoded);

        if (isset($renderings[$rendering])) {
            expect(bin2hex($bytes))->toBe($renderings[$rendering]);
        }

        $renderings[$rendering] = bin2hex($bytes);
    }
})->with(RandomValues::SEEDS);

it('pretty-prints on request', function () {
    expect(CanonicalJson::encode([1, 2], pretty: true))->toBe("[\n    1,\n    2\n]");
});
