<?php

declare(strict_types=1);

namespace Yjs\Tests\Support;

use stdClass;
use Yjs\Binary\AnyValue\BigInt;
use Yjs\Binary\AnyValue\Bytes;
use Yjs\Binary\AnyValue\Undefined;

/**
 * Value equality with JavaScript's `Object.is` semantics.
 *
 * PHP's `==` says `NAN != NAN` and `-0.0 == 0.0`, and both of those would let a
 * real round-trip bug pass: negative zero has its own encoding, and a NaN that
 * came back as something else would go unnoticed. Floats are therefore compared
 * by their bits.
 */
final class ValueComparison
{
    private function __construct() {}

    public static function same(mixed $expected, mixed $actual): bool
    {
        return match (true) {
            is_float($expected) || is_float($actual) => is_float($expected)
                && is_float($actual)
                && pack('E', $expected) === pack('E', $actual),
            $expected instanceof Undefined => $actual instanceof Undefined,
            $expected instanceof BigInt => $actual instanceof BigInt && $expected->equals($actual),
            $expected instanceof Bytes => $actual instanceof Bytes && $expected->equals($actual),
            $expected instanceof stdClass => $actual instanceof stdClass && self::sameObject($expected, $actual),
            is_array($expected) => is_array($actual) && self::sameArray($expected, $actual),
            default => $expected === $actual,
        };
    }

    public static function describe(mixed $value): string
    {
        return match (true) {
            $value instanceof Undefined => 'undefined',
            $value instanceof BigInt => "BigInt({$value->value})",
            $value instanceof Bytes => 'Bytes(0x'.bin2hex($value->bytes).')',
            is_float($value) => 'float(0x'.bin2hex(pack('E', $value)).')',
            is_string($value) => 'string(0x'.bin2hex($value).')',
            default => var_export($value, true),
        };
    }

    private static function sameArray(array $expected, array $actual): bool
    {
        if (count($expected) !== count($actual) || array_keys($expected) !== array_keys($actual)) {
            return false;
        }

        foreach ($expected as $key => $value) {
            if (! self::same($value, $actual[$key])) {
                return false;
            }
        }

        return true;
    }

    private static function sameObject(stdClass $expected, stdClass $actual): bool
    {
        $expectedProperties = get_object_vars($expected);
        $actualProperties = get_object_vars($actual);

        // Key order is part of the value here: the wire preserves insertion
        // order, so an object that re-encodes with its keys shuffled would
        // produce different bytes.
        if (array_keys($expectedProperties) !== array_keys($actualProperties)) {
            return false;
        }

        foreach ($expectedProperties as $key => $value) {
            if (! self::same($value, $actualProperties[$key])) {
                return false;
            }
        }

        return true;
    }
}
