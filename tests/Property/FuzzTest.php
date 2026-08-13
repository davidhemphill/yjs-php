<?php

declare(strict_types=1);

use Yjs\Binary\DecodeLimits;
use Yjs\Binary\Decoder;
use Yjs\Binary\Encoder;
use Yjs\Exception\DecodeException;
use Yjs\Exception\LimitExceeded;
use Yjs\Tests\Support\RandomValues;

/**
 * Bounded failure on input nobody vetted.
 *
 * The property is not "the decoder rejects garbage" — plenty of garbage decodes
 * to something valid. It is that the decoder only ever returns a value or
 * throws a DecodeException. A TypeError, a PHP warning, an `unpack()` returning
 * false, or a runaway allocation would each be a way for hostile bytes to reach
 * past the decoder, and each fails this test.
 */

/**
 * Every read the decoder exposes, so the fuzzer reaches the fixed-width paths
 * as well as the self-describing ones.
 *
 * @return array<string, Closure(Decoder): mixed>
 */
function decoderEntryPoints(): array
{
    return [
        'readAny' => fn (Decoder $decoder) => $decoder->readAny(),
        'readVarUint' => fn (Decoder $decoder) => $decoder->readVarUint(),
        'readVarInt' => fn (Decoder $decoder) => $decoder->readVarIntPreservingSign(),
        'readVarString' => fn (Decoder $decoder) => $decoder->readVarString(),
        'readVarBytes' => fn (Decoder $decoder) => $decoder->readVarBytes(),
        'readFloat32' => fn (Decoder $decoder) => $decoder->readFloat32(),
        'readFloat64' => fn (Decoder $decoder) => $decoder->readFloat64(),
        'readBigInt64' => fn (Decoder $decoder) => $decoder->readBigInt64(),
        'readUint16' => fn (Decoder $decoder) => $decoder->readUint16(),
        'readUint32' => fn (Decoder $decoder) => $decoder->readUint32(),
        'readUint32BigEndian' => fn (Decoder $decoder) => $decoder->readUint32BigEndian(),
    ];
}

/**
 * Run one read and fail unless it either returned or threw a DecodeException.
 */
function expectBoundedFailure(string $bytes, Closure $read, string $context): void
{
    try {
        $read(new Decoder($bytes, DecodeLimits::strict()));
    } catch (DecodeException) {
        // The only acceptable failure.
    } catch (Throwable $unexpected) {
        throw new RuntimeException(sprintf(
            '%s leaked a %s on input 0x%s: %s',
            $context,
            $unexpected::class,
            bin2hex($bytes),
            $unexpected->getMessage(),
        ), previous: $unexpected);
    }
}

it('never leaks an untyped failure on arbitrary bytes', function (int $seed) {
    mt_srand($seed);

    foreach (decoderEntryPoints() as $name => $read) {
        for ($iteration = 0; $iteration < 400; $iteration++) {
            expectBoundedFailure(RandomValues::bytes(mt_rand(0, 64)), $read, $name);
        }
    }

    expect(true)->toBeTrue();
})->with(RandomValues::SEEDS);

it('never leaks an untyped failure on a truncated valid encoding', function (int $seed) {
    mt_srand($seed);

    for ($iteration = 0; $iteration < 60; $iteration++) {
        $bytes = (new Encoder)->writeAny(RandomValues::any())->toBytes();

        for ($length = 0; $length < strlen($bytes); $length++) {
            expectBoundedFailure(
                substr($bytes, 0, $length),
                fn (Decoder $decoder) => $decoder->readAny(),
                "truncation to {$length} bytes",
            );
        }
    }

    expect(true)->toBeTrue();
})->with([1, 42, 20260813]);

it('never leaks an untyped failure when a valid encoding is mutated', function (int $seed) {
    mt_srand($seed);

    for ($iteration = 0; $iteration < 300; $iteration++) {
        $bytes = (new Encoder)->writeAny(RandomValues::any())->toBytes();

        $position = mt_rand(0, strlen($bytes) - 1);
        $bytes[$position] = chr(mt_rand(0, 255));

        expectBoundedFailure(
            $bytes,
            fn (Decoder $decoder) => $decoder->readAny(),
            "mutation at offset {$position}",
        );
    }

    expect(true)->toBeTrue();
})->with([1, 42, 20260813]);

it('rejects a hostile header without reserving what it asks for', function () {
    // Declares a two gigabyte byte array inside a five byte input. The point of
    // the assertion is that rejecting it is cheap: the declared length alone is
    // enough, so nothing gets allocated on the way to the exception.
    $declaresTwoGigabytes = "\x80\x80\x80\x80\x08";

    $before = memory_get_usage(true);

    expect(fn () => (new Decoder($declaresTwoGigabytes))->readVarBytes())
        ->toThrow(LimitExceeded::class);

    expect(memory_get_usage(true) - $before)->toBeLessThan(1024 * 1024);
});
