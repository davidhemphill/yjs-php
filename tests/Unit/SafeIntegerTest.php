<?php

declare(strict_types=1);

use Yjs\Binary\SafeInteger;
use Yjs\Exception\IntegerOutOfRange;

it('knows the JavaScript safe integer boundaries', function () {
    expect(SafeInteger::MAX)->toBe(2 ** 53 - 1)
        ->and(SafeInteger::MIN)->toBe(-(2 ** 53 - 1));
});

it('accepts values inside the safe range', function (int|float $value) {
    expect(SafeInteger::isSafe($value))->toBeTrue();
})->with([0, 1, -1, 9007199254740991, -9007199254740991, 42.0, -0.0]);

it('rejects values outside the safe range', function (int|float $value) {
    expect(SafeInteger::isSafe($value))->toBeFalse();
})->with([
    'one past max' => 9007199254740992,
    'one before min' => -9007199254740992,
    'php int max' => PHP_INT_MAX,
    'php int min' => PHP_INT_MIN,
    'fraction' => 1.5,
    'infinity' => INF,
    'nan' => NAN,
]);

it('throws when asserting an unsafe value', function () {
    expect(fn () => SafeInteger::assert(PHP_INT_MAX))->toThrow(IntegerOutOfRange::class);
});

it('throws when a non-negative value is required', function () {
    expect(fn () => SafeInteger::assertNonNegative(-1))->toThrow(IntegerOutOfRange::class);
    expect(SafeInteger::assertNonNegative(0))->toBe(0);
});

it('narrows an integral float to an int', function () {
    expect(SafeInteger::assert(42.0))->toBe(42);
});
