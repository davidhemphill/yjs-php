<?php

declare(strict_types=1);

use Hemp\Yjs\Binary\Utf16;
use Hemp\Yjs\Tests\Support\Fixtures;

/**
 * The split Yjs performs when it cuts string content at a clock, checked
 * against the real `ContentString.splice`.
 *
 * The interesting case is a boundary that falls between the halves of a
 * surrogate pair. Yjs substitutes U+FFFD on both sides, which damages the
 * character but keeps each half exactly as long as the surrounding clocks
 * already assume. Getting the substitution right but the length wrong would be
 * worse than useless: it would corrupt every clock after the split.
 */
$splitCases = array_map(
    fn (array $case) => [$case],
    Fixtures::cases('utf16-split'),
);

it('splits where Yjs splits', function (array $case) {
    expect(Utf16::split($case['subject'], $case['offset']))
        ->toBe([$case['left'], $case['right']]);
})->with($splitCases);

it('preserves the length of each half', function (array $case) {
    [$left, $right] = Utf16::split($case['subject'], $case['offset']);

    // The lengths come from JavaScript, so this compares against what Yjs's
    // own clock arithmetic will expect rather than against our own count.
    expect(Utf16::length($left))->toBe($case['leftLength'])
        ->and(Utf16::length($right))->toBe($case['rightLength'])
        ->and(Utf16::length($left) + Utf16::length($right))->toBe(Utf16::length($case['subject']));
})->with($splitCases);

it('reports whether a surrogate pair was damaged', function (array $case) {
    expect(Utf16::splitsSurrogatePair($case['subject'], $case['offset']))
        ->toBe($case['damagedSurrogatePair']);
})->with($splitCases);

it('rejoins into the original unless a pair was damaged', function (array $case) {
    [$left, $right] = Utf16::split($case['subject'], $case['offset']);

    if ($case['damagedSurrogatePair']) {
        // One astral character became two replacement characters. The text is
        // genuinely lost; only the lengths survive.
        expect($left.$right)->not->toBe($case['subject'])
            ->and($left)->toEndWith(Utf16::REPLACEMENT)
            ->and($right)->toStartWith(Utf16::REPLACEMENT);

        return;
    }

    expect($left.$right)->toBe($case['subject']);
})->with($splitCases);

it('always produces valid UTF-8', function (array $case) {
    // The point of the substitution: a lone surrogate has no UTF-8 encoding, so
    // a split that produced one would be a string PHP could not even hold.
    [$left, $right] = Utf16::split($case['subject'], $case['offset']);

    expect(mb_check_encoding($left, 'UTF-8'))->toBeTrue()
        ->and(mb_check_encoding($right, 'UTF-8'))->toBeTrue();
})->with($splitCases);

it('handles offsets outside the string', function () {
    expect(Utf16::split('abc', 0))->toBe(['', 'abc'])
        ->and(Utf16::split('abc', -5))->toBe(['', 'abc'])
        ->and(Utf16::split('abc', 3))->toBe(['abc', ''])
        ->and(Utf16::split('abc', 99))->toBe(['abc', ''])
        ->and(Utf16::split('', 0))->toBe(['', ''])
        ->and(Utf16::split('', 5))->toBe(['', '']);
});

it('splits cleanly when the boundary respects the pair', function () {
    expect(Utf16::split('a😀b', 1))->toBe(['a', '😀b'])
        ->and(Utf16::split('a😀b', 3))->toBe(['a😀', 'b'])
        ->and(Utf16::splitsSurrogatePair('a😀b', 1))->toBeFalse()
        ->and(Utf16::splitsSurrogatePair('a😀b', 3))->toBeFalse();
});
