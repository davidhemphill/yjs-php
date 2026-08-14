<?php

declare(strict_types=1);

use Hemp\Yjs\Binary\Utf16;
use Hemp\Yjs\Exception\EncodeException;
use Hemp\Yjs\Tests\Support\Fixtures;

/**
 * The UTF-16 helpers, checked against lengths and slices taken in JavaScript.
 *
 * These are the numbers Yjs actually uses to address string content, so being
 * off by one here would show up much later as a struct split in the wrong place
 * rather than as an obvious failure.
 */
$cases = Fixtures::cases('utf16');

$lengthCases = array_map(
    fn (array $case) => [$case],
    array_filter($cases, fn (array $case) => $case['kind'] === 'length'),
);

$sliceCases = array_map(
    fn (array $case) => [$case],
    array_filter($cases, fn (array $case) => $case['kind'] === 'slice'),
);

it('counts the code units JavaScript would count', function (array $case) {
    expect(Utf16::length($case['subject']))->toBe($case['utf16Length']);
})->with($lengthCases);

it('slices at the offsets JavaScript slices at', function (array $case) {
    if ($case['splitsSurrogatePair']) {
        // JavaScript hands back a lone surrogate here. UTF-8 cannot encode one,
        // so the only honest answers are to refuse or to corrupt the text.
        expect(fn () => Utf16::slice($case['subject'], $case['offset'], $case['length']))
            ->toThrow(EncodeException::class);

        return;
    }

    expect(Utf16::slice($case['subject'], $case['offset'], $case['length']))->toBe($case['expected']);
})->with($sliceCases);

it('slices to the end when no length is given', function () {
    expect(Utf16::slice('a😀b', 1))->toBe('😀b')
        ->and(Utf16::slice('abc', 0))->toBe('abc')
        ->and(Utf16::slice('abc', 3))->toBe('');
});

it('disagrees with the UTF-8 byte length exactly where it should', function () {
    expect(Utf16::length('😀'))->toBe(2)
        ->and(strlen('😀'))->toBe(4)
        ->and(Utf16::length('abc'))->toBe(3)
        ->and(strlen('abc'))->toBe(3);
});
