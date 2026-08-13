<?php

declare(strict_types=1);

namespace Yjs\Binary;

use stdClass;
use Yjs\Binary\AnyValue\BigInt;
use Yjs\Binary\AnyValue\Bytes;
use Yjs\Binary\AnyValue\Undefined;
use Yjs\Exception\IntegerOutOfRange;
use Yjs\Exception\LimitExceeded;
use Yjs\Exception\MalformedInput;
use Yjs\Exception\UnexpectedEndOfInput;

/**
 * Reads the lib0 primitives out of a byte string.
 *
 * This is the library's entire untrusted-input surface. Every read is bounded
 * before it allocates, every failure is a typed exception, and no path here can
 * warn, hang, or reserve memory proportional to a number an attacker chose.
 */
final class Decoder
{
    /**
     * A rough per-element memory charge for declared collection sizes, so that
     * a large count is billed against the allocation budget even when the
     * elements themselves are one-byte tags.
     */
    private const int ELEMENT_ALLOCATION_ESTIMATE = 16;

    private int $position = 0;

    private readonly int $length;

    private int $allocated = 0;

    private int $depth = 0;

    public function __construct(
        private readonly string $bytes,
        private readonly DecodeLimits $limits = new DecodeLimits,
    ) {
        $this->length = strlen($bytes);
    }

    public function position(): int
    {
        return $this->position;
    }

    public function remaining(): int
    {
        return $this->length - $this->position;
    }

    public function hasMore(): bool
    {
        return $this->position < $this->length;
    }

    /**
     * @throws MalformedInput
     */
    public function assertAtEnd(): void
    {
        if ($this->hasMore()) {
            throw MalformedInput::trailingBytes($this->remaining(), $this->position);
        }
    }

    /**
     * Read raw bytes with no length prefix.
     *
     * @throws UnexpectedEndOfInput
     */
    public function readBytes(int $count): string
    {
        if ($count > $this->remaining()) {
            throw UnexpectedEndOfInput::needing($count, $this->remaining(), $this->position);
        }

        $slice = substr($this->bytes, $this->position, $count);
        $this->position += $count;

        return $slice;
    }

    /**
     * @throws UnexpectedEndOfInput
     */
    public function readUint8(): int
    {
        if ($this->position >= $this->length) {
            throw UnexpectedEndOfInput::needing(1, 0, $this->position);
        }

        return ord($this->bytes[$this->position++]);
    }

    public function readUint16(): int
    {
        return unpack('v', $this->readBytes(2))[1];
    }

    public function readUint32(): int
    {
        return unpack('V', $this->readBytes(4))[1];
    }

    public function readUint32BigEndian(): int
    {
        return unpack('N', $this->readBytes(4))[1];
    }

    /**
     * Unsigned LEB128, bounded to the safe integer range.
     *
     * lib0 has no byte cap here and will return an unsafe integer if the final
     * byte pushes it past 2^53 - 1. We reject instead: every varUint on the Yjs
     * wire is a client ID, clock, length, or count, and none of those are
     * meaningful once they stop being exactly representable.
     *
     * @throws IntegerOutOfRange
     * @throws UnexpectedEndOfInput
     */
    public function readVarUint(): int
    {
        $start = $this->position;
        $number = 0;
        $multiplier = 1;

        for ($byteIndex = 0; $byteIndex < SafeInteger::MAX_VARINT_BYTES; $byteIndex++) {
            $byte = $this->readUint8();
            $number += ($byte & 0x7F) * $multiplier;
            $multiplier *= 128;

            if ($byte < 0x80) {
                if ($number > SafeInteger::MAX) {
                    throw IntegerOutOfRange::atPosition($start);
                }

                return $number;
            }
        }

        throw IntegerOutOfRange::atPosition($start);
    }

    /**
     * lib0's signed variable-length integer.
     *
     * @throws IntegerOutOfRange
     * @throws UnexpectedEndOfInput
     */
    public function readVarInt(): int
    {
        return (int) $this->readVarIntPreservingSign();
    }

    /**
     * The same read, except that the negative zero encoding comes back as the
     * float `-0.0` rather than collapsing into `0`.
     *
     * Yjs never writes a negative zero, but a hostile or hand-built update can,
     * and a value that decodes to `0` and re-encodes to different bytes would
     * quietly break any byte-level comparison we make later.
     *
     * @throws IntegerOutOfRange
     * @throws UnexpectedEndOfInput
     */
    public function readVarIntPreservingSign(): int|float
    {
        $start = $this->position;
        $byte = $this->readUint8();

        $number = $byte & 0x3F;
        $multiplier = 64;
        $isNegative = ($byte & 0x40) > 0;

        if (($byte & 0x80) === 0) {
            return self::applySign($number, $isNegative);
        }

        for ($byteIndex = 1; $byteIndex < SafeInteger::MAX_VARINT_BYTES; $byteIndex++) {
            $byte = $this->readUint8();
            $number += ($byte & 0x7F) * $multiplier;
            $multiplier *= 128;

            if ($byte < 0x80) {
                if ($number > SafeInteger::MAX) {
                    throw IntegerOutOfRange::atPosition($start);
                }

                return self::applySign($number, $isNegative);
            }
        }

        throw IntegerOutOfRange::atPosition($start);
    }

    /**
     * A length-prefixed byte array.
     *
     * @throws LimitExceeded
     * @throws UnexpectedEndOfInput
     */
    public function readVarBytes(): string
    {
        $start = $this->position;
        $declared = $this->readVarUint();

        if ($declared > $this->limits->maxByteLength) {
            throw LimitExceeded::byteLength($declared, $this->limits->maxByteLength, $start);
        }

        if ($declared > $this->remaining()) {
            throw UnexpectedEndOfInput::needing($declared, $this->remaining(), $this->position);
        }

        $this->account($declared);

        return $this->readBytes($declared);
    }

    /**
     * A length-prefixed UTF-8 string.
     *
     * lib0 decodes through `TextDecoder`, which silently substitutes U+FFFD for
     * malformed sequences. We reject instead. Every real client encodes with
     * `TextEncoder`, which cannot emit invalid UTF-8, so anything that fails
     * here is corruption or an attack rather than a document we would lose.
     *
     * @throws MalformedInput
     */
    public function readVarString(): string
    {
        $start = $this->position;
        $raw = $this->readVarBytes();

        if (! mb_check_encoding($raw, 'UTF-8')) {
            throw MalformedInput::invalidUtf8($start);
        }

        return $raw;
    }

    public function readFloat32(): float
    {
        return unpack('G', $this->readBytes(4))[1];
    }

    public function readFloat64(): float
    {
        return unpack('E', $this->readBytes(8))[1];
    }

    /**
     * PHP integers are already signed 64-bit, so unpacking as unsigned and
     * letting the sign bit land where it falls reproduces `getBigInt64`.
     */
    public function readBigInt64(): int
    {
        return unpack('J', $this->readBytes(8))[1];
    }

    /**
     * Read a self-describing lib0 "any" value.
     *
     * @throws LimitExceeded
     * @throws MalformedInput
     */
    public function readAny(): mixed
    {
        $start = $this->position;
        $tag = $this->readVarUint();
        $type = AnyType::tryFrom($tag);

        if ($type === null) {
            throw MalformedInput::unknownAnyTag($tag, $start);
        }

        return match ($type) {
            AnyType::Undefined => Undefined::instance(),
            AnyType::Null => null,
            // The sign-preserving read, because lib0 computes `sign * num` here
            // and hands back a negative zero for the 0x40 encoding. Collapsing
            // it to 0 would re-encode to different bytes.
            AnyType::Integer => $this->readVarIntPreservingSign(),
            AnyType::Float32 => $this->readFloat32(),
            AnyType::Float64 => $this->readFloat64(),
            AnyType::BigInt64 => new BigInt($this->readBigInt64()),
            AnyType::False => false,
            AnyType::True => true,
            AnyType::String => $this->readVarString(),
            AnyType::Object => $this->readAnyObject(),
            AnyType::Array => $this->readAnyArray(),
            AnyType::Bytes => new Bytes($this->readVarBytes()),
        };
    }

    /**
     * @return list<mixed>
     */
    private function readAnyArray(): array
    {
        $count = $this->readCount(minimumBytesPerElement: 1);

        return $this->nested(function () use ($count): array {
            $values = [];

            for ($index = 0; $index < $count; $index++) {
                $values[] = $this->readAny();
            }

            return $values;
        });
    }

    /**
     * lib0 objects decode to `stdClass` rather than an associative array.
     *
     * PHP cannot tell an empty list from an empty map, and the two are separate
     * tags on the wire, so an object that arrived as `{}` has to come back as
     * something that will not re-encode as `[]`.
     */
    private function readAnyObject(): stdClass
    {
        $count = $this->readCount(minimumBytesPerElement: 2);

        return $this->nested(function () use ($count): stdClass {
            $object = new stdClass;

            for ($index = 0; $index < $count; $index++) {
                $key = $this->readVarString();
                $object->{$key} = $this->readAny();
            }

            return $object;
        });
    }

    /**
     * Read a declared element count and reject it before anything is reserved.
     *
     * The configured limit is a policy ceiling; the remaining-bytes check is an
     * exact one. Every element costs at least some minimum number of bytes, so
     * a count larger than the bytes left cannot possibly be honest.
     *
     * @param  int  $minimumBytesPerElement  The smallest an element can encode to.
     *
     * @throws LimitExceeded
     */
    public function readCount(int $minimumBytesPerElement): int
    {
        $start = $this->position;
        $count = $this->readVarUint();

        if ($count > $this->limits->maxElementCount) {
            throw LimitExceeded::elementCount($count, $this->limits->maxElementCount, $start);
        }

        if ($count > intdiv($this->remaining(), $minimumBytesPerElement)) {
            throw LimitExceeded::elementCount($count, intdiv($this->remaining(), $minimumBytesPerElement), $start);
        }

        $this->account($count * self::ELEMENT_ALLOCATION_ESTIMATE);

        return $count;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $read
     * @return T
     *
     * @throws LimitExceeded
     */
    private function nested(callable $read): mixed
    {
        if ($this->depth >= $this->limits->maxDepth) {
            throw LimitExceeded::depth($this->limits->maxDepth, $this->position);
        }

        $this->depth++;

        try {
            return $read();
        } finally {
            $this->depth--;
        }
    }

    /**
     * @throws LimitExceeded
     */
    private function account(int $bytes): void
    {
        if ($bytes > $this->limits->maxTotalAllocation - $this->allocated) {
            throw LimitExceeded::allocation($bytes, $this->allocated, $this->limits->maxTotalAllocation);
        }

        $this->allocated += $bytes;
    }

    private static function applySign(int $number, bool $isNegative): int|float
    {
        if (! $isNegative) {
            return $number;
        }

        return $number === 0 ? -0.0 : -$number;
    }
}
