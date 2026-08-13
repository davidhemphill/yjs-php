<?php

declare(strict_types=1);

use Yjs\Binary\AnyValue\BigInt;
use Yjs\Binary\AnyValue\Bytes;
use Yjs\Binary\AnyValue\Undefined;
use Yjs\Binary\DecodeLimits;
use Yjs\Binary\Decoder;
use Yjs\Binary\Encoder;
use Yjs\Binary\SafeInteger;
use Yjs\Binary\Utf16;
use Yjs\Exception\EncodeException;
use Yjs\Tests\Support\RandomValues;
use Yjs\Tests\Support\ValueComparison;

/**
 * Randomized round-trip properties over the primitives.
 *
 * Seeds are fixed so every failure reproduces from the case name alone.
 */
it('round-trips random safe integers through varUint', function (int $seed) {
    mt_srand($seed);

    for ($iteration = 0; $iteration < 500; $iteration++) {
        $value = mt_rand(0, 1) === 0
            ? mt_rand(0, 65535)
            : mt_rand(0, SafeInteger::MAX);

        $bytes = (new Encoder)->writeVarUint($value)->toBytes();

        expect((new Decoder($bytes))->readVarUint())->toBe($value);
    }
})->with(RandomValues::SEEDS);

it('round-trips random safe integers through varInt', function (int $seed) {
    mt_srand($seed);

    for ($iteration = 0; $iteration < 500; $iteration++) {
        $value = mt_rand(SafeInteger::MIN, SafeInteger::MAX);

        $bytes = (new Encoder)->writeVarInt($value)->toBytes();

        expect((new Decoder($bytes))->readVarInt())->toBe($value);
    }
})->with(RandomValues::SEEDS);

it('round-trips random unicode strings', function (int $seed) {
    mt_srand($seed);

    for ($iteration = 0; $iteration < 200; $iteration++) {
        $text = RandomValues::text();

        $bytes = (new Encoder)->writeVarString($text)->toBytes();

        expect((new Decoder($bytes, DecodeLimits::trusted()))->readVarString())->toBe($text);
    }
})->with(RandomValues::SEEDS);

it('measures a random string in UTF-16 units that bracket its other lengths', function (int $seed) {
    mt_srand($seed);

    for ($iteration = 0; $iteration < 200; $iteration++) {
        $text = RandomValues::text();

        // One UTF-16 unit per code point at minimum, two at most, and never
        // more units than the string has UTF-8 bytes.
        expect(Utf16::length($text))
            ->toBeGreaterThanOrEqual(mb_strlen($text, 'UTF-8'))
            ->toBeLessThanOrEqual(2 * mb_strlen($text, 'UTF-8'))
            ->toBeLessThanOrEqual(strlen($text));
    }
})->with(RandomValues::SEEDS);

/**
 * The invariant the update algebra will rest on: splitting string content at a
 * clock always yields two halves whose lengths still add up, whatever the text
 * and wherever the boundary falls. If this ever fails, every clock after the
 * split is wrong, and the failure surfaces far from its cause.
 */
it('preserves total length when splitting at any offset', function (int $seed) {
    mt_srand($seed);

    for ($iteration = 0; $iteration < 100; $iteration++) {
        $text = RandomValues::text();
        $units = Utf16::length($text);

        for ($offset = 0; $offset <= $units; $offset++) {
            [$head, $tail] = Utf16::split($text, $offset);

            expect(Utf16::length($head))->toBe($offset)
                ->and(Utf16::length($head) + Utf16::length($tail))->toBe($units);

            // A lone surrogate has no UTF-8 encoding, so a split that produced
            // one would not even be a string PHP could hold.
            expect(mb_check_encoding($head, 'UTF-8'))->toBeTrue()
                ->and(mb_check_encoding($tail, 'UTF-8'))->toBeTrue();

            // Text survives intact exactly when no pair was damaged.
            expect($head.$tail === $text)->toBe(! Utf16::splitsSurrogatePair($text, $offset));
        }
    }
})->with(RandomValues::SEEDS);

it('reassembles a random string from a UTF-16 slice and its remainder', function (int $seed) {
    mt_srand($seed);

    for ($iteration = 0; $iteration < 200; $iteration++) {
        $text = RandomValues::text();
        $units = Utf16::length($text);

        if ($units === 0) {
            continue;
        }

        $split = mt_rand(0, $units);

        try {
            $head = Utf16::slice($text, 0, $split);
            $tail = Utf16::slice($text, $split);
        } catch (EncodeException) {
            // The split landed inside a surrogate pair, which has no UTF-8
            // form. Refusing is the documented behavior, so there is nothing
            // to reassemble.
            continue;
        }

        expect($head.$tail)->toBe($text);
    }
})->with(RandomValues::SEEDS);

/**
 * A round trip is a fixed point: encoding and decoding once may normalize the
 * value, but doing it again must change nothing, in either the value or the
 * bytes.
 *
 * This is stated as a fixed point rather than as strict identity because one
 * normalization is real and intended. A whole PHP float takes lib0's integer
 * tag and decodes back as a PHP int, because that is precisely what lib0 does
 * with a whole JavaScript Number — the language just cannot show you the
 * difference. Demanding `5.0` back would be demanding that PHP disagree with
 * the format. What must never happen is a value that keeps drifting, and that
 * is what these assertions pin down.
 */
it('reaches a fixed point after one round trip', function (int $seed) {
    mt_srand($seed);

    for ($iteration = 0; $iteration < 200; $iteration++) {
        $bytes = (new Encoder)->writeAny(RandomValues::any())->toBytes();
        $decoded = (new Decoder($bytes, DecodeLimits::trusted()))->readAny();

        $reencoded = (new Encoder)->writeAny($decoded)->toBytes();
        $redecoded = (new Decoder($reencoded, DecodeLimits::trusted()))->readAny();

        // Byte stability is what the update algebra will depend on in Phase 3:
        // two values that compare equal but encode differently would break
        // every byte-level comparison made downstream.
        expect(bin2hex($reencoded))->toBe(bin2hex($bytes));

        expect(ValueComparison::same($decoded, $redecoded))->toBeTrue(sprintf(
            'Second round trip changed %s into %s.',
            ValueComparison::describe($decoded),
            ValueComparison::describe($redecoded),
        ));
    }
})->with(RandomValues::SEEDS);

/**
 * The values that are not subject to that normalization must survive a single
 * round trip exactly, including the ones PHP's own comparison operators get
 * wrong: NaN, negative zero, and an empty object against an empty list.
 */
it('round-trips exactly for values the wire can tell apart', function (int $seed) {
    mt_srand($seed);

    $exactValues = [
        null,
        Undefined::instance(),
        true,
        false,
        NAN,
        INF,
        -INF,
        -0.0,
        0.1,
        M_PI,
        new stdClass,
        [],
        new Bytes(''),
        new BigInt(PHP_INT_MIN),
    ];

    for ($iteration = 0; $iteration < 100; $iteration++) {
        $exactValues[] = RandomValues::text();
        $exactValues[] = new Bytes(RandomValues::bytes(mt_rand(0, 32)));
        $exactValues[] = mt_rand(-2147483647, 2147483647);
    }

    foreach ($exactValues as $value) {
        $bytes = (new Encoder)->writeAny($value)->toBytes();
        $decoded = (new Decoder($bytes, DecodeLimits::trusted()))->readAny();

        expect(ValueComparison::same($value, $decoded))->toBeTrue(sprintf(
            'Round trip changed %s into %s.',
            ValueComparison::describe($value),
            ValueComparison::describe($decoded),
        ));
    }
})->with(RandomValues::SEEDS);

it('consumes exactly the bytes it produced', function (int $seed) {
    mt_srand($seed);

    for ($iteration = 0; $iteration < 200; $iteration++) {
        $bytes = (new Encoder)->writeAny(RandomValues::any())->toBytes();

        $decoder = new Decoder($bytes, DecodeLimits::trusted());
        $decoder->readAny();

        expect(fn () => $decoder->assertAtEnd())->not->toThrow(Throwable::class);
    }
})->with(RandomValues::SEEDS);
