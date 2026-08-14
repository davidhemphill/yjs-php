<?php

declare(strict_types=1);

namespace Hemp\Yjs\Binary;

use Hemp\Yjs\Binary\AnyValue\BigInt;
use Hemp\Yjs\Binary\AnyValue\Bytes;
use Hemp\Yjs\Binary\AnyValue\Undefined;
use Hemp\Yjs\Exception\EncodeException;
use stdClass;

/**
 * Writes the lib0 primitives that the Yjs V1 update format is built from.
 *
 * Every method here is a byte-for-byte counterpart of the lib0 function of the
 * same name. Where lib0 has a choice to make — which of three tags a number
 * gets, whether a negative zero keeps its sign — this class makes the same
 * choice, because "close enough" bytes still fail to converge.
 */
final class Encoder
{
    /**
     * Largest value lib0 will tag as an "any" integer rather than a float.
     */
    private const int BITS31 = 0x7FFFFFFF;

    private string $buffer = '';

    public function length(): int
    {
        return strlen($this->buffer);
    }

    public function toBytes(): string
    {
        return $this->buffer;
    }

    /**
     * Append bytes verbatim, with no length prefix.
     */
    public function writeBytes(string $bytes): self
    {
        $this->buffer .= $bytes;

        return $this;
    }

    public function writeUint8(int $byte): self
    {
        $this->buffer .= chr($byte & 0xFF);

        return $this;
    }

    public function writeUint16(int $value): self
    {
        $this->buffer .= pack('v', $value & 0xFFFF);

        return $this;
    }

    public function writeUint32(int $value): self
    {
        $this->buffer .= pack('V', $value & 0xFFFFFFFF);

        return $this;
    }

    public function writeUint32BigEndian(int $value): self
    {
        $this->buffer .= pack('N', $value & 0xFFFFFFFF);

        return $this;
    }

    /**
     * Unsigned LEB128, seven bits per byte, high bit marks continuation.
     *
     * @throws EncodeException
     */
    public function writeVarUint(int|float $value): self
    {
        $number = $this->toSafeInteger($value);

        if ($number < 0) {
            throw new EncodeException(sprintf(
                'writeVarUint expects a non-negative integer, got %s.',
                var_export($value, true),
            ));
        }

        while ($number > 0x7F) {
            $this->buffer .= chr(0x80 | (0x7F & $number));
            $number = intdiv($number, 128);
        }

        return $this->writeUint8(0x7F & $number);
    }

    /**
     * lib0's signed variable-length integer: six payload bits and a sign bit in
     * the first byte, seven bits per byte after that.
     *
     * Pass `-0.0` to write the negative zero encoding. PHP has no negative zero
     * integer, so the float is the only way to ask for those bytes, and it is
     * the shape {@see Decoder::readVarIntPreservingSign()} gives back.
     *
     * @throws EncodeException
     */
    public function writeVarInt(int|float $value): self
    {
        $isNegative = self::isNegativeZero($value);

        if ($isNegative) {
            $value = -$value;
        }

        $number = $this->toSafeInteger($value);

        $this->writeUint8(
            ($number > 0x3F ? 0x80 : 0) | ($isNegative ? 0x40 : 0) | (0x3F & $number)
        );

        $number = intdiv($number, 64);

        while ($number > 0) {
            $this->writeUint8(($number > 0x7F ? 0x80 : 0) | (0x7F & $number));
            $number = intdiv($number, 128);
        }

        return $this;
    }

    /**
     * A length-prefixed byte array — lib0's `writeVarUint8Array`.
     */
    public function writeVarBytes(string $bytes): self
    {
        return $this->writeVarUint(strlen($bytes))->writeBytes($bytes);
    }

    /**
     * A length-prefixed UTF-8 string, where the length counts *bytes*.
     *
     * @throws EncodeException When the string is not valid UTF-8.
     */
    public function writeVarString(string $value): self
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            throw EncodeException::invalidUtf8();
        }

        return $this->writeVarBytes($value);
    }

    /**
     * lib0 writes floats through a `DataView` without asking for little-endian,
     * so all three of these are big-endian while the fixed-width integers above
     * are little-endian. The inconsistency is lib0's; we have to match it.
     */
    public function writeFloat32(float $value): self
    {
        return $this->writeBytes(pack('G', $value));
    }

    public function writeFloat64(float $value): self
    {
        return $this->writeBytes(pack('E', $value));
    }

    public function writeBigInt64(int $value): self
    {
        return $this->writeBytes(pack('J', $value));
    }

    /**
     * Write an arbitrary value using lib0's self-describing "any" encoding.
     *
     * @throws EncodeException
     */
    public function writeAny(mixed $value): self
    {
        return match (true) {
            $value instanceof Undefined => $this->writeTag(AnyType::Undefined),
            $value === null => $this->writeTag(AnyType::Null),
            is_bool($value) => $this->writeTag($value ? AnyType::True : AnyType::False),
            is_int($value), is_float($value) => $this->writeAnyNumber($value),
            is_string($value) => $this->writeTag(AnyType::String)->writeVarString($value),
            $value instanceof BigInt => $this->writeTag(AnyType::BigInt64)->writeBigInt64($value->value),
            $value instanceof Bytes => $this->writeTag(AnyType::Bytes)->writeVarBytes($value->bytes),
            is_array($value) && array_is_list($value) => $this->writeAnyArray($value),
            is_array($value) => $this->writeAnyObject($value),
            $value instanceof stdClass => $this->writeAnyObject(get_object_vars($value)),
            default => throw EncodeException::unsupportedValue($value),
        };
    }

    private function writeTag(AnyType $type): self
    {
        return $this->writeVarUint($type->value);
    }

    /**
     * Reproduce lib0's three-way choice between the integer, float32, and
     * float64 tags. The order of the checks is what decides the bytes.
     */
    private function writeAnyNumber(int|float $value): self
    {
        if (self::isJavaScriptInteger($value) && abs($value) <= self::BITS31) {
            return $this->writeTag(AnyType::Integer)->writeVarInt($value);
        }

        $float = (float) $value;

        if (self::isFloat32($float)) {
            return $this->writeTag(AnyType::Float32)->writeFloat32($float);
        }

        return $this->writeTag(AnyType::Float64)->writeFloat64($float);
    }

    /**
     * @param  list<mixed>  $values
     */
    private function writeAnyArray(array $values): self
    {
        $this->writeTag(AnyType::Array)->writeVarUint(count($values));

        foreach ($values as $value) {
            $this->writeAny($value);
        }

        return $this;
    }

    /**
     * @param  array<array-key, mixed>  $entries
     */
    private function writeAnyObject(array $entries): self
    {
        $this->writeTag(AnyType::Object)->writeVarUint(count($entries));

        foreach ($entries as $key => $value) {
            $this->writeVarString((string) $key)->writeAny($value);
        }

        return $this;
    }

    /**
     * `Number.isInteger` — false for infinities and NaN, true for any float
     * that happens to have no fractional part.
     */
    private static function isJavaScriptInteger(int|float $value): bool
    {
        return is_int($value) || (is_finite($value) && floor($value) === $value);
    }

    /**
     * Whether a double survives a round trip through single precision, which is
     * exactly the test lib0 runs before choosing the float32 tag.
     *
     * NaN fails this comparison in both languages and so lands on float64 in
     * both, which is the behavior we want rather than a special case.
     */
    private static function isFloat32(float $value): bool
    {
        return unpack('G', pack('G', $value))[1] === $value;
    }

    /**
     * lib0 treats every negative number, and negative zero, as "negative" when
     * choosing the sign bit.
     *
     * lib0 separates the two zeroes with `1 / n < 0`, which PHP 8 cannot copy
     * literally: dividing by zero throws here where JavaScript returns an
     * infinity. `fdiv` is the operation JavaScript's `/` actually is.
     */
    private static function isNegativeZero(int|float $value): bool
    {
        if ($value != 0) {
            return $value < 0;
        }

        return is_float($value) && fdiv(1, $value) < 0;
    }

    /**
     * @throws EncodeException
     */
    private function toSafeInteger(int|float $value): int
    {
        if (is_float($value) && (! is_finite($value) || floor($value) !== $value)) {
            throw EncodeException::nonIntegralFloat($value);
        }

        if (! SafeInteger::isSafe($value)) {
            throw new EncodeException(sprintf(
                'Value %s is outside the safe integer range (%d..%d).',
                var_export($value, true),
                SafeInteger::MIN,
                SafeInteger::MAX,
            ));
        }

        return (int) $value;
    }
}
