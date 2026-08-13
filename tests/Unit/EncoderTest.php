<?php

declare(strict_types=1);

use Yjs\Binary\AnyValue\BigInt;
use Yjs\Binary\AnyValue\Bytes;
use Yjs\Binary\AnyValue\Undefined;
use Yjs\Binary\Decoder;
use Yjs\Binary\Encoder;
use Yjs\Exception\EncodeException;

describe('input validation', function () {
    it('refuses a negative varUint', function () {
        expect(fn () => (new Encoder)->writeVarUint(-1))->toThrow(EncodeException::class);
    });

    it('refuses an integer past the safe range', function () {
        expect(fn () => (new Encoder)->writeVarUint(9007199254740992))->toThrow(EncodeException::class);
        expect(fn () => (new Encoder)->writeVarInt(-9007199254740992))->toThrow(EncodeException::class);
    });

    it('refuses a float with a fractional part', function () {
        expect(fn () => (new Encoder)->writeVarUint(1.5))->toThrow(EncodeException::class);
        expect(fn () => (new Encoder)->writeVarInt(-2.25))->toThrow(EncodeException::class);
    });

    it('refuses an infinity or a NaN where an integer is required', function () {
        expect(fn () => (new Encoder)->writeVarUint(INF))->toThrow(EncodeException::class);
        expect(fn () => (new Encoder)->writeVarInt(NAN))->toThrow(EncodeException::class);
    });

    it('refuses a string that is not valid UTF-8', function () {
        expect(fn () => (new Encoder)->writeVarString("\xC3\x28"))->toThrow(EncodeException::class);
    });

    it('writes the same bytes as an opaque byte array', function () {
        expect((new Encoder)->writeVarBytes("\xC3\x28")->toBytes())->toBeBytes("\x02\xC3\x28");
    });

    it('refuses a value the wire has no representation for', function () {
        expect(fn () => (new Encoder)->writeAny(fopen('php://memory', 'r')))->toThrow(EncodeException::class);
        expect(fn () => (new Encoder)->writeAny(new DateTimeImmutable))->toThrow(EncodeException::class);
    });
});

describe('container disambiguation', function () {
    /**
     * PHP has one array type where JavaScript has two, so the choice of tag
     * comes from the shape of the array and from the wrapper types. Getting
     * this wrong is silent: both sides decode, they just disagree.
     */
    it('tags a list as an array and a map as an object', function () {
        expect((new Encoder)->writeAny([1, 2])->toBytes()[0])->toBeBytes("\x75");
        expect((new Encoder)->writeAny(['a' => 1])->toBytes()[0])->toBeBytes("\x76");
    });

    it('tags an empty PHP array as an array', function () {
        expect((new Encoder)->writeAny([])->toBytes())->toBeBytes("\x75\x00");
    });

    it('tags an empty stdClass as an object', function () {
        expect((new Encoder)->writeAny(new stdClass)->toBytes())->toBeBytes("\x76\x00");
    });

    it('round-trips an empty object as an object rather than an array', function () {
        $bytes = (new Encoder)->writeAny(new stdClass)->toBytes();
        $decoded = (new Decoder($bytes))->readAny();

        expect($decoded)->toBeInstanceOf(stdClass::class);
        expect((new Encoder)->writeAny($decoded)->toBytes())->toBeBytes($bytes);
    });

    it('keeps a string apart from a byte array', function () {
        expect((new Encoder)->writeAny('ab')->toBytes())->toBeBytes("\x77\x02ab");
        expect((new Encoder)->writeAny(new Bytes('ab'))->toBytes())->toBeBytes("\x74\x02ab");
    });

    it('keeps undefined apart from null', function () {
        expect((new Encoder)->writeAny(Undefined::instance())->toBytes())->toBeBytes("\x7F");
        expect((new Encoder)->writeAny(null)->toBytes())->toBeBytes("\x7E");
    });

    it('keeps a bigint apart from an integer', function () {
        expect((new Encoder)->writeAny(new BigInt(1))->toBytes())->toBeBytes("\x7A\x00\x00\x00\x00\x00\x00\x00\x01");
        expect((new Encoder)->writeAny(1)->toBytes())->toBeBytes("\x7D\x01");
    });
});

describe('number tag selection', function () {
    /**
     * lib0 picks between three tags in a fixed order, and the thresholds are
     * not where you would guess: a whole number past 2^31 - 1 does not stay an
     * integer, it becomes a float32 if single precision happens to hold it.
     */
    it('tags an integer PHP float the way JavaScript tags a whole Number', function () {
        expect((new Encoder)->writeAny(5.0)->toBytes())->toBeBytes((new Encoder)->writeAny(5)->toBytes());
    });

    /**
     * The one place a round trip does not return the type it was given. lib0
     * has a single tag for both, because JavaScript has a single type for both,
     * so a whole float comes back as an int. Asserting it here keeps the
     * behavior deliberate rather than surprising.
     */
    it('returns a whole float as an int', function () {
        $bytes = (new Encoder)->writeAny(5.0)->toBytes();

        expect((new Decoder($bytes))->readAny())->toBe(5);
    });

    it('keeps a float that is not whole a float', function () {
        $bytes = (new Encoder)->writeAny(5.5)->toBytes();

        expect((new Decoder($bytes))->readAny())->toBe(5.5);
    });

    it('leaves the integer tag at 2^31 - 1', function () {
        expect((new Encoder)->writeAny(2147483647)->toBytes()[0])->toBeBytes("\x7D");
        expect((new Encoder)->writeAny(2147483648)->toBytes()[0])->toBeBytes("\x7C");
    });

    it('prefers float32 for a whole number single precision can hold', function () {
        expect((new Encoder)->writeAny(2 ** 40)->toBytes()[0])->toBeBytes("\x7C");
        expect((new Encoder)->writeAny(2 ** 40 + 1)->toBytes()[0])->toBeBytes("\x7B");
    });

    it('writes NaN as a float64', function () {
        expect((new Encoder)->writeAny(NAN)->toBytes())->toBeBytes("\x7B\x7F\xF8\x00\x00\x00\x00\x00\x00");
    });

    it('preserves the sign of a negative zero', function () {
        expect((new Encoder)->writeAny(-0.0)->toBytes())->toBeBytes("\x7D\x40");
        expect((new Encoder)->writeAny(0.0)->toBytes())->toBeBytes("\x7D\x00");
    });
});

describe('endianness', function () {
    /**
     * lib0 writes its fixed-width integers little-endian and its floats
     * big-endian, because the floats go through a DataView that defaults the
     * other way. Both directions are asserted so neither can drift.
     */
    it('writes fixed-width integers little-endian', function () {
        expect((new Encoder)->writeUint16(0x1234)->toBytes())->toBeBytes("\x34\x12");
        expect((new Encoder)->writeUint32(0x12345678)->toBytes())->toBeBytes("\x78\x56\x34\x12");
    });

    it('writes the big-endian variant when asked', function () {
        expect((new Encoder)->writeUint32BigEndian(0x12345678)->toBytes())->toBeBytes("\x12\x34\x56\x78");
    });

    it('writes floats and bigints big-endian', function () {
        expect((new Encoder)->writeFloat32(1.0)->toBytes())->toBeBytes("\x3F\x80\x00\x00");
        expect((new Encoder)->writeFloat64(1.0)->toBytes())->toBeBytes("\x3F\xF0\x00\x00\x00\x00\x00\x00");
        expect((new Encoder)->writeBigInt64(1)->toBytes())->toBeBytes("\x00\x00\x00\x00\x00\x00\x00\x01");
    });
});
