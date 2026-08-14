<?php

declare(strict_types=1);

use Hemp\Yjs\Tests\Support\ValueComparison;

/**
 * Assert value equality with `Object.is` semantics — see ValueComparison for
 * why PHP's own comparison operators are not good enough here.
 */
expect()->extend('toBeSameValueAs', function (mixed $expected) {
    $actual = $this->value;

    expect(ValueComparison::same($expected, $actual))->toBeTrue(sprintf(
        'Expected %s, got %s.',
        ValueComparison::describe($expected),
        ValueComparison::describe($actual),
    ));

    return $this;
});

/**
 * Assert the exact bytes, reporting the difference in hex. A failure that says
 * "7c4f000000 !== 7b41e0000000000000" is readable; one that dumps raw binary
 * into the terminal is not.
 */
expect()->extend('toBeBytes', function (string $expected) {
    expect(bin2hex($this->value))->toBe(bin2hex($expected));

    return $this;
});
