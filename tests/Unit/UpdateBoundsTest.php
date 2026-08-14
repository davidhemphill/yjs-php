<?php

declare(strict_types=1);

use Hemp\Yjs\Binary\DecodeLimits;
use Hemp\Yjs\Exception\DecodeException;
use Hemp\Yjs\Exception\LimitExceeded;
use Hemp\Yjs\Exception\MalformedInput;
use Hemp\Yjs\Tests\Support\Fixtures;
use Hemp\Yjs\Tests\Support\RandomValues;
use Hemp\Yjs\Update\Update;

/**
 * The update reader against malformed input.
 *
 * An update arrives from a socket before anyone is authorized to send it, so
 * this reader is as exposed as the primitive decoder underneath it. The bar is
 * the same: a value or a typed DecodeException, never a crash and never an
 * allocation proportional to a number the sender chose.
 */
it('rejects an unknown content reference', function () {
    // One section, one struct, content ref 11 — past the nine Profile 1 defines
    // and not the GC or Skip marker either. The parent fields have to be well
    // formed for the read to get as far as the content, otherwise this would
    // only be testing that the input ran out.
    $bytes = "\x01\x01\x01\x00".chr(11)."\x01\x04root";

    expect(fn () => Update::decode($bytes))->toThrow(MalformedInput::class);
});

it('rejects an unknown shared type reference', function () {
    // Content ref 7 with a type ref of 99.
    $bytes = "\x01\x01\x01\x00\x07\x01\x04root\x63";

    expect(fn () => Update::decode($bytes))->toThrow(MalformedInput::class);
});

it('rejects a section count larger than the bytes that remain', function () {
    // Claims a million client sections in a four byte update. Each section
    // needs at least three bytes, so this cannot be honest at any limit.
    expect(fn () => Update::decode("\xC0\x84\x3D\x00", DecodeLimits::trusted()))
        ->toThrow(LimitExceeded::class);
});

it('rejects a struct count larger than the bytes that remain', function () {
    expect(fn () => Update::decode("\x01\xC0\x84\x3D\x01\x00", DecodeLimits::trusted()))
        ->toThrow(LimitExceeded::class);
});

it('rejects a delete range count larger than the bytes that remain', function () {
    // No structs, then a delete set claiming a million ranges for one client.
    expect(fn () => Update::decode("\x00\x01\x01\xC0\x84\x3D", DecodeLimits::trusted()))
        ->toThrow(LimitExceeded::class);
});

it('rejects a clock outside the safe integer range', function () {
    // A section whose starting clock is 2^53.
    $bytes = "\x01\x01\x01".str_repeat("\x80", 7)."\x10";

    expect(fn () => Update::decode($bytes))->toThrow(DecodeException::class);
});

it('rejects trailing bytes after a complete update', function () {
    $valid = base64_decode(Fixtures::cases('updates')['text-plain']['update'], strict: true);

    expect(fn () => Update::decode($valid."\xFF", DecodeLimits::trusted()))
        ->toThrow(MalformedInput::class);
});

it('rejects a truncated update', function () {
    $valid = base64_decode(Fixtures::cases('updates')['map-any']['update'], strict: true);

    expect(fn () => Update::decode(substr($valid, 0, -1), DecodeLimits::trusted()))
        ->toThrow(DecodeException::class);
});

/**
 * The same bounded-failure property the primitives have, applied to whole
 * updates: truncating or corrupting a real update may produce something
 * readable, but never anything untyped.
 */
it('fails bounded on every truncation of a real update', function (string $name) {
    $valid = base64_decode(Fixtures::cases('updates')[$name]['update'], strict: true);

    for ($length = 0; $length < strlen($valid); $length++) {
        try {
            Update::decode(substr($valid, 0, $length), DecodeLimits::trusted());
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
})->with(['text-formatted', 'map-any', 'nested-types', 'skip-structs', 'deletes-across-clients']);

it('fails bounded when a real update is corrupted', function (int $seed) {
    mt_srand($seed);

    $names = array_keys(Fixtures::cases('updates'));

    for ($iteration = 0; $iteration < 400; $iteration++) {
        $name = $names[mt_rand(0, count($names) - 1)];
        $bytes = base64_decode(Fixtures::cases('updates')[$name]['update'], strict: true);

        if ($bytes === '') {
            continue;
        }

        $position = mt_rand(0, strlen($bytes) - 1);
        $bytes[$position] = chr(mt_rand(0, 255));

        try {
            Update::decode($bytes, DecodeLimits::trusted());
        } catch (DecodeException) {
            continue;
        } catch (Throwable $unexpected) {
            throw new RuntimeException(sprintf(
                '%s corrupted at offset %d leaked a %s: %s',
                $name,
                $position,
                $unexpected::class,
                $unexpected->getMessage(),
            ), previous: $unexpected);
        }
    }

    expect(true)->toBeTrue();
})->with(RandomValues::SEEDS);
