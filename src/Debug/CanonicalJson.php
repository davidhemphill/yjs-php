<?php

declare(strict_types=1);

namespace Yjs\Debug;

use JsonException;
use stdClass;
use Yjs\Binary\AnyValue\BigInt;
use Yjs\Binary\AnyValue\Bytes;
use Yjs\Binary\AnyValue\Undefined;
use Yjs\Exception\EncodeException;

/**
 * A stable, unambiguous JSON rendering of a decoded value.
 *
 * When a fixture disagrees, the useful question is *which part* disagreed, and
 * a hex dump does not answer it. Plain `json_encode` does not either: it shows
 * `5` for both an int and a whole float, `null` for both null and undefined,
 * `{}` for an empty object and an empty map, and refuses NaN outright — which
 * is to say it erases every distinction that actually costs time to debug.
 *
 * So every value that PHP's own JSON cannot represent faithfully is written as
 * a single-key tagged object, and objects keep their key order explicitly. Two
 * values render identically only when they encode identically.
 */
final class CanonicalJson
{
    private function __construct() {}

    /**
     * @throws EncodeException When the value is not something lib0 can carry.
     */
    public static function encode(mixed $value, bool $pretty = false): string
    {
        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            | ($pretty ? JSON_PRETTY_PRINT : 0);

        try {
            return json_encode(self::represent($value), $flags);
        } catch (JsonException $failure) {
            throw new EncodeException('Could not render the value as canonical JSON.', previous: $failure);
        }
    }

    /**
     * Rewrite a value into a shape `json_encode` can render without losing
     * anything.
     */
    public static function represent(mixed $value): mixed
    {
        return match (true) {
            $value instanceof Undefined => ['$undefined' => true],
            $value === null => null,
            is_bool($value) => $value,
            is_int($value) => $value,
            is_float($value) => ['$float' => self::float($value)],
            is_string($value) => $value,
            $value instanceof BigInt => ['$bigint' => (string) $value->value],
            $value instanceof Bytes => ['$bytes' => bin2hex($value->bytes)],
            is_array($value) && array_is_list($value) => array_map(self::represent(...), $value),
            is_array($value) => self::object($value),
            $value instanceof stdClass => self::object(get_object_vars($value)),
            default => throw EncodeException::unsupportedValue($value),
        };
    }

    /**
     * Floats are tagged so they never collide with an integer, and the values
     * JSON has no syntax for are spelled out.
     *
     * Negative zero gets its own spelling because it has its own encoding, and
     * `json_encode(-0.0)` is just `-0.0` rendered as `-0` on some platforms and
     * `0` on others.
     */
    private static function float(float $value): string|float
    {
        return match (true) {
            is_nan($value) => 'NaN',
            $value === INF => 'Infinity',
            $value === -INF => '-Infinity',
            $value === 0.0 && fdiv(1, $value) < 0 => '-0',
            default => $value,
        };
    }

    /**
     * Objects render as an ordered list of pairs.
     *
     * Key order is part of the value here — it decides the bytes — and a JSON
     * object would neither preserve it visibly nor keep a key like `$bytes`
     * from being mistaken for one of the tags above.
     *
     * @param  array<array-key, mixed>  $entries
     */
    private static function object(array $entries): array
    {
        return [
            '$object' => array_map(
                fn (string|int $key, mixed $value): array => [(string) $key, self::represent($value)],
                array_keys($entries),
                array_values($entries),
            ),
        ];
    }
}
