<?php

declare(strict_types=1);

use Yjs\Binary\DecodeLimits;
use Yjs\Binary\Decoder;
use Yjs\Binary\Encoder;
use Yjs\Exception\DecodeException;
use Yjs\Exception\IntegerOutOfRange;
use Yjs\Exception\LimitExceeded;
use Yjs\Exception\MalformedInput;
use Yjs\Exception\UnexpectedEndOfInput;

/**
 * The decoder's behavior on input that is truncated, oversized, or hostile.
 *
 * Every case here asserts a *typed* failure. Returning a wrong answer, warning,
 * hanging, or allocating what the header asked for would each be a separate way
 * of failing this suite, and only one of them looks like a crash.
 */
describe('truncated input', function () {
    it('refuses to read a byte that is not there', function () {
        expect(fn () => (new Decoder(''))->readUint8())
            ->toThrow(UnexpectedEndOfInput::class);
    });

    it('refuses a fixed-width read that runs off the end', function () {
        expect(fn () => (new Decoder("\x01\x02"))->readUint32())
            ->toThrow(UnexpectedEndOfInput::class);

        expect(fn () => (new Decoder("\x01\x02\x03\x04"))->readFloat64())
            ->toThrow(UnexpectedEndOfInput::class);
    });

    it('refuses a varUint whose continuation bit never terminates', function () {
        expect(fn () => (new Decoder("\x80\x80\x80"))->readVarUint())
            ->toThrow(UnexpectedEndOfInput::class);
    });

    it('refuses a varInt whose continuation bit never terminates', function () {
        expect(fn () => (new Decoder("\xC0\x80"))->readVarInt())
            ->toThrow(UnexpectedEndOfInput::class);
    });

    it('refuses a length-prefixed array longer than the input', function () {
        // Declares 100 bytes, supplies three.
        expect(fn () => (new Decoder("\x64\x01\x02\x03"))->readVarBytes())
            ->toThrow(UnexpectedEndOfInput::class);
    });
});

describe('integer range', function () {
    it('refuses a varUint above the safe integer range', function () {
        // Seven empty groups then 16, which is 16 * 128^7 — exactly 2^53, one
        // past the largest integer JavaScript can hold without rounding.
        $twoToTheFiftyThree = str_repeat("\x80", 7)."\x10";

        expect(fn () => (new Decoder($twoToTheFiftyThree))->readVarUint())
            ->toThrow(IntegerOutOfRange::class);
    });

    it('accepts the largest safe integer', function () {
        $maxSafe = str_repeat("\xFF", 7)."\x0F";

        expect((new Decoder($maxSafe))->readVarUint())->toBe(9007199254740991);
    });

    it('refuses a varUint padded past the widest legal encoding', function () {
        expect(fn () => (new Decoder(str_repeat("\x80", 9)."\x00"))->readVarUint())
            ->toThrow(IntegerOutOfRange::class);
    });

    it('refuses a varInt above the safe integer range', function () {
        expect(fn () => (new Decoder(str_repeat("\xC0", 8)."\x7F"))->readVarInt())
            ->toThrow(IntegerOutOfRange::class);
    });
});

describe('declared sizes', function () {
    it('rejects a declared byte length past the limit before allocating', function () {
        // Declares 16 MB inside a four-byte input. Nothing should be reserved.
        $declaresSixteenMegabytes = "\x80\x80\x80\x08";

        expect(fn () => (new Decoder($declaresSixteenMegabytes, DecodeLimits::strict()))->readVarBytes())
            ->toThrow(LimitExceeded::class);
    });

    it('rejects an element count larger than the bytes that remain', function () {
        // An "any" array claiming a million elements, with nothing after it.
        // Each element costs at least its own tag byte, so this cannot be true
        // regardless of how the configured limits are set.
        $lyingArrayHeader = "\x75\xC0\x84\x3D";

        expect(fn () => (new Decoder($lyingArrayHeader, DecodeLimits::trusted()))->readAny())
            ->toThrow(LimitExceeded::class);
    });

    it('rejects an element count past the configured limit', function () {
        $thousandElements = "\x75\xE8\x07".str_repeat("\x7E", 1000);

        expect(fn () => (new Decoder($thousandElements, DecodeLimits::strict()))->readAny())
            ->toThrow(LimitExceeded::class);
    });

    it('rejects nesting deeper than the configured limit', function () {
        $deeplyNested = str_repeat("\x75\x01", 40)."\x7E";

        expect(fn () => (new Decoder($deeplyNested, DecodeLimits::strict()))->readAny())
            ->toThrow(LimitExceeded::class);
    });

    it('rejects a decode whose cumulative allocation passes the budget', function () {
        $encoder = new Encoder;
        $encoder->writeAny(array_fill(0, 20, str_repeat('x', 4000)));

        expect(fn () => (new Decoder($encoder->toBytes(), DecodeLimits::strict()))->readAny())
            ->toThrow(LimitExceeded::class);
    });

    it('allows the same decode under limits that fit it', function () {
        $encoder = new Encoder;
        $encoder->writeAny(array_fill(0, 20, str_repeat('x', 4000)));

        expect((new Decoder($encoder->toBytes(), DecodeLimits::trusted()))->readAny())
            ->toHaveCount(20);
    });
});

describe('malformed input', function () {
    it('rejects an unknown "any" type tag', function () {
        expect(fn () => (new Decoder("\x01"))->readAny())
            ->toThrow(MalformedInput::class);
    });

    it('rejects a string that is not valid UTF-8', function () {
        // A length of two followed by a bare continuation byte pair.
        expect(fn () => (new Decoder("\x02\xC3\x28"))->readVarString())
            ->toThrow(MalformedInput::class);
    });

    it('rejects a truncated multi-byte character', function () {
        // The first two bytes of a four-byte emoji, declared as the whole string.
        expect(fn () => (new Decoder("\x02\xF0\x9F"))->readVarString())
            ->toThrow(MalformedInput::class);
    });

    it('reads the same bytes as an opaque byte array without complaint', function () {
        expect((new Decoder("\x02\xC3\x28"))->readVarBytes())->toBe("\xC3\x28");
    });

    it('reports trailing bytes when a full read is expected', function () {
        $decoder = new Decoder("\x01\xFF\xFF");
        $decoder->readVarUint();

        expect(fn () => $decoder->assertAtEnd())->toThrow(MalformedInput::class);
    });
});

describe('exception hierarchy', function () {
    it('reports every decode failure as a DecodeException', function (string $bytes, Closure $read) {
        expect(fn () => $read(new Decoder($bytes, DecodeLimits::strict())))
            ->toThrow(DecodeException::class);
    })->with([
        'truncated' => ['', fn (Decoder $decoder) => $decoder->readUint8()],
        'out of range' => [str_repeat("\x80", 7)."\x10", fn (Decoder $decoder) => $decoder->readVarUint()],
        'over limit' => ["\x80\x80\x80\x08", fn (Decoder $decoder) => $decoder->readVarBytes()],
        'malformed' => ["\x01", fn (Decoder $decoder) => $decoder->readAny()],
    ]);
});
