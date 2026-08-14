<?php

declare(strict_types=1);

namespace Hemp\Yjs\Tests\Support;

use Hemp\Yjs\Binary\AnyValue\BigInt;
use Hemp\Yjs\Binary\AnyValue\Bytes;
use Hemp\Yjs\Binary\AnyValue\Undefined;
use stdClass;

/**
 * Deterministic random value generation for the property tests.
 *
 * Everything here draws from `mt_rand`, seeded by the caller. A property
 * failure therefore reproduces from its seed alone, and a seed that finds a bug
 * gets pinned into {@see self::SEEDS} so the same case runs forever after.
 */
final class RandomValues
{
    /**
     * Seeds every property runs against. Add a failing seed here rather than
     * fixing the bug and moving on.
     */
    public const array SEEDS = [1, 7, 13, 42, 1337, 20260813];

    /**
     * Characters spanning every UTF-8 width, including the astral and combining
     * sequences that make UTF-16 and UTF-8 lengths disagree.
     */
    private const array ALPHABET = [
        'a', 'Z', '0', ' ', '"', '\\',
        'é', 'ß', 'Ж',
        '日', '本', '﷽',
        '😀', '👍🏽', '👨‍👩‍👧‍👦', '🇯🇵',
    ];

    private function __construct() {}

    public static function bytes(int $length): string
    {
        $bytes = '';

        for ($index = 0; $index < $length; $index++) {
            $bytes .= chr(mt_rand(0, 255));
        }

        return $bytes;
    }

    public static function text(): string
    {
        $text = '';

        for ($index = 0, $length = mt_rand(0, 12); $index < $length; $index++) {
            $text .= self::ALPHABET[mt_rand(0, count(self::ALPHABET) - 1)];
        }

        return $text;
    }

    /**
     * A value drawn from every shape the lib0 "any" encoding can carry,
     * including the ones PHP would otherwise never produce: negative zero,
     * undefined, an empty object distinct from an empty list.
     */
    public static function any(int $depth = 0): mixed
    {
        $lastLeaf = 10;
        $choice = mt_rand(0, $depth >= 3 ? $lastLeaf : $lastLeaf + 2);

        return match ($choice) {
            0 => null,
            1 => Undefined::instance(),
            2 => (bool) mt_rand(0, 1),
            3 => mt_rand(-2147483647, 2147483647),
            4 => mt_rand(-1000, 1000) / 7,
            5 => self::text(),
            6 => new Bytes(self::bytes(mt_rand(0, 32))),
            7 => new BigInt(mt_rand(PHP_INT_MIN, PHP_INT_MAX)),
            8 => 0.0,
            9 => -0.0,
            10 => (float) mt_rand(-1000, 1000),
            11 => self::list($depth),
            default => self::object($depth),
        };
    }

    /**
     * @return list<mixed>
     */
    public static function list(int $depth): array
    {
        $values = [];

        for ($index = 0, $length = mt_rand(0, 4); $index < $length; $index++) {
            $values[] = self::any($depth + 1);
        }

        return $values;
    }

    public static function object(int $depth): stdClass
    {
        $object = new stdClass;

        for ($index = 0, $length = mt_rand(0, 4); $index < $length; $index++) {
            $object->{"key{$index}"} = self::any($depth + 1);
        }

        return $object;
    }
}
