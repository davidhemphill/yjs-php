<?php

declare(strict_types=1);

namespace Hemp\Yjs\Binary;

use Hemp\Yjs\Exception\EncodeException;

/**
 * Yjs measures string content in UTF-16 code units because that is what a
 * JavaScript `String` counts. PHP measures the same text in UTF-8 bytes.
 *
 * The two only agree below U+0080, so a struct that is split at "clock + 3"
 * has to be split at the third UTF-16 code unit, not the third byte. Keeping
 * that conversion in one place is what stops it from being rediscovered — and
 * gotten wrong — at each slicing site.
 */
final class Utf16
{
    /**
     * U+FFFD REPLACEMENT CHARACTER, in UTF-8.
     */
    public const string REPLACEMENT = "\u{FFFD}";

    private function __construct() {}

    /**
     * The length JavaScript would report for this text.
     *
     * Characters outside the Basic Multilingual Plane are one PHP code point
     * but a surrogate pair — two units — in JavaScript.
     */
    public static function length(string $utf8): int
    {
        $units = 0;

        foreach (self::codePoints($utf8) as $codePoint) {
            $units += $codePoint > 0xFFFF ? 2 : 1;
        }

        return $units;
    }

    /**
     * Extract a substring by UTF-16 offsets and return it as UTF-8.
     *
     * A slice that would cut a surrogate pair in half is a real possibility on
     * the wire — Yjs itself splits string content at arbitrary clocks. There
     * is no UTF-8 encoding of half a surrogate pair, so rather than emit
     * mojibake we refuse, and the caller decides how to widen the split.
     *
     * @param  int  $offset  Start offset in UTF-16 code units.
     * @param  int|null  $length  Length in UTF-16 code units, or null for "to the end".
     *
     * @throws EncodeException When either boundary falls inside a surrogate pair.
     */
    public static function slice(string $utf8, int $offset, ?int $length = null): string
    {
        $end = $length === null ? PHP_INT_MAX : $offset + $length;

        // An empty slice extracts nothing, so no boundary can cut a character
        // in half — not even one that lands between two surrogates. JavaScript
        // returns "" for these rather than a lone surrogate, and so do we.
        if ($end <= $offset) {
            return '';
        }

        $unit = 0;
        $sliced = '';

        foreach (self::codePoints($utf8) as $codePoint) {
            $width = $codePoint > 0xFFFF ? 2 : 1;

            if ($unit >= $offset && $unit + $width <= $end) {
                $sliced .= self::encodeCodePoint($codePoint);
            } elseif ($width === 2 && (self::straddles($unit, $offset) || self::straddles($unit, $end))) {
                throw new EncodeException(sprintf(
                    'UTF-16 offset %d falls inside a surrogate pair.',
                    self::straddles($unit, $offset) ? $offset : $end,
                ));
            }

            $unit += $width;

            if ($unit >= $end) {
                break;
            }
        }

        return $sliced;
    }

    /**
     * Split text at a UTF-16 offset the way Yjs splits string content.
     *
     * This is the operation the update algebra needs: an Item carrying string
     * content gets split at a clock, and the clock counts UTF-16 units, so the
     * boundary can land between the halves of a surrogate pair.
     *
     * Yjs resolves that by replacing the broken pair with U+FFFD on *both*
     * sides rather than refusing or emitting lone surrogates, and the reason is
     * arithmetic rather than taste: a high surrogate is one UTF-16 unit and
     * U+FFFD is one UTF-16 unit, so each side keeps exactly the length the
     * clocks already assume. Widening the split, or refusing it, would change
     * those lengths and invalidate every clock after it.
     *
     * The content is genuinely damaged when this happens — one astral character
     * becomes two replacement characters. Yjs accepts that, because the
     * alternative is a document that cannot be encoded at all. See
     * https://github.com/yjs/yjs/issues/248.
     *
     * @return array{0: string, 1: string} The left and right halves.
     */
    public static function split(string $utf8, int $offset): array
    {
        if ($offset <= 0) {
            return ['', $utf8];
        }

        $unit = 0;
        $left = '';
        $right = '';

        foreach (self::codePoints($utf8) as $codePoint) {
            $width = $codePoint > 0xFFFF ? 2 : 1;

            if ($unit + $width <= $offset) {
                $left .= self::encodeCodePoint($codePoint);
            } elseif ($unit >= $offset) {
                $right .= self::encodeCodePoint($codePoint);
            } else {
                // The offset falls strictly inside this character, which only a
                // surrogate pair can manage. One unit to each side, so both
                // halves keep the length they are supposed to have.
                $left .= self::REPLACEMENT;
                $right .= self::REPLACEMENT;
            }

            $unit += $width;
        }

        return [$left, $right];
    }

    /**
     * Whether splitting here would damage a surrogate pair.
     *
     * Worth checking when the caller wants to count or log how often real
     * content is being degraded, which {@see self::split()} will not tell them.
     */
    public static function splitsSurrogatePair(string $utf8, int $offset): bool
    {
        if ($offset <= 0) {
            return false;
        }

        $unit = 0;

        foreach (self::codePoints($utf8) as $codePoint) {
            $width = $codePoint > 0xFFFF ? 2 : 1;

            if ($unit < $offset && $offset < $unit + $width) {
                return true;
            }

            if ($unit >= $offset) {
                return false;
            }

            $unit += $width;
        }

        return false;
    }

    /**
     * Whether a UTF-16 boundary lands between the two halves of the surrogate
     * pair that starts at $unit.
     */
    private static function straddles(int $unit, int $boundary): bool
    {
        return $boundary === $unit + 1;
    }

    /**
     * @return iterable<int>
     */
    private static function codePoints(string $utf8): iterable
    {
        $codePoints = unpack('N*', mb_convert_encoding($utf8, 'UTF-32BE', 'UTF-8'));

        return $codePoints === false ? [] : $codePoints;
    }

    private static function encodeCodePoint(int $codePoint): string
    {
        return mb_convert_encoding(pack('N', $codePoint), 'UTF-8', 'UTF-32BE');
    }
}
