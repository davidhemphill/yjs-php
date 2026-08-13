<?php

declare(strict_types=1);

use Yjs\Environment;

it('runs on a 64-bit build', function () {
    expect(Environment::is64Bit())->toBeTrue()
        ->and(fn () => Environment::assertSupported())->not->toThrow(Throwable::class);
});

it('can hold the largest safe integer without promoting to a float', function () {
    // The guard exists for exactly this: on a 32-bit build the assertion below
    // would fail because the literal would already have become a float.
    expect(9007199254740991)->toBeInt();
});
