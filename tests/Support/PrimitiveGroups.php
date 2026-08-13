<?php

declare(strict_types=1);

namespace Yjs\Tests\Support;

use Closure;
use Yjs\Binary\Decoder;
use Yjs\Binary\Encoder;

/**
 * The mapping from a fixture group to the reader and writer it exercises.
 *
 * Shared by the golden-byte tests and by the tool that emits PHP encodings for
 * the JavaScript oracle to check, so that both directions are proven over the
 * same table and neither can quietly cover less than the other.
 */
final class PrimitiveGroups
{
    private function __construct() {}

    /**
     * @return array<string, array{
     *     encode: Closure(Encoder, mixed): Encoder,
     *     decode: Closure(Decoder): mixed,
     *     value: 'realize'|'unwrap',
     * }>
     */
    public static function all(): array
    {
        return [
            'var-uint' => [
                'encode' => fn (Encoder $encoder, $value) => $encoder->writeVarUint($value),
                'decode' => fn (Decoder $decoder) => $decoder->readVarUint(),
                'value' => 'realize',
            ],
            'var-int' => [
                'encode' => fn (Encoder $encoder, $value) => $encoder->writeVarInt($value),
                // The sign-preserving read, so the negative zero fixture is a
                // real assertion rather than a comparison of 0 against 0.
                'decode' => fn (Decoder $decoder) => $decoder->readVarIntPreservingSign(),
                'value' => 'realize',
            ],
            'uint8' => [
                'encode' => fn (Encoder $encoder, $value) => $encoder->writeUint8($value),
                'decode' => fn (Decoder $decoder) => $decoder->readUint8(),
                'value' => 'realize',
            ],
            'uint16' => [
                'encode' => fn (Encoder $encoder, $value) => $encoder->writeUint16($value),
                'decode' => fn (Decoder $decoder) => $decoder->readUint16(),
                'value' => 'realize',
            ],
            'uint32' => [
                'encode' => fn (Encoder $encoder, $value) => $encoder->writeUint32($value),
                'decode' => fn (Decoder $decoder) => $decoder->readUint32(),
                'value' => 'realize',
            ],
            'uint32-big-endian' => [
                'encode' => fn (Encoder $encoder, $value) => $encoder->writeUint32BigEndian($value),
                'decode' => fn (Decoder $decoder) => $decoder->readUint32BigEndian(),
                'value' => 'realize',
            ],
            'float32' => [
                'encode' => fn (Encoder $encoder, $value) => $encoder->writeFloat32($value),
                'decode' => fn (Decoder $decoder) => $decoder->readFloat32(),
                'value' => 'realize',
            ],
            'float64' => [
                'encode' => fn (Encoder $encoder, $value) => $encoder->writeFloat64($value),
                'decode' => fn (Decoder $decoder) => $decoder->readFloat64(),
                'value' => 'realize',
            ],
            'big-int64' => [
                'encode' => fn (Encoder $encoder, $value) => $encoder->writeBigInt64($value),
                'decode' => fn (Decoder $decoder) => $decoder->readBigInt64(),
                'value' => 'unwrap',
            ],
            'var-bytes' => [
                'encode' => fn (Encoder $encoder, $value) => $encoder->writeVarBytes($value),
                'decode' => fn (Decoder $decoder) => $decoder->readVarBytes(),
                'value' => 'unwrap',
            ],
            'var-string' => [
                'encode' => fn (Encoder $encoder, $value) => $encoder->writeVarString($value),
                'decode' => fn (Decoder $decoder) => $decoder->readVarString(),
                'value' => 'realize',
            ],
            'any' => [
                'encode' => fn (Encoder $encoder, $value) => $encoder->writeAny($value),
                'decode' => fn (Decoder $decoder) => $decoder->readAny(),
                'value' => 'realize',
            ],
        ];
    }

    /**
     * Realize a fixture case's value in the shape its writer expects.
     */
    public static function valueFor(string $group, array $case): mixed
    {
        return self::all()[$group]['value'] === 'unwrap'
            ? Fixtures::unwrap($case['value'])
            : Fixtures::realize($case['value']);
    }
}
