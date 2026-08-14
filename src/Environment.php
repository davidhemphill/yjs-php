<?php

declare(strict_types=1);

namespace Hemp\Yjs;

use Hemp\Yjs\Exception\UnsupportedPlatform;

/**
 * The platform assumptions this library is allowed to make.
 */
final class Environment
{
    private function __construct() {}

    public static function is64Bit(): bool
    {
        return PHP_INT_SIZE >= 8;
    }

    /**
     * Refuse to run on a platform where the arithmetic would be wrong.
     *
     * Yjs clocks and client IDs go up to 2^53 - 1. On a 32-bit build PHP's
     * integers top out at 2^31 - 1 and silently promote to floats past that, so
     * a decoder would keep running and start returning clocks that are close to
     * right. A refusal at load time is the only safe failure.
     *
     * @throws UnsupportedPlatform
     */
    public static function assertSupported(): void
    {
        if (! self::is64Bit()) {
            throw UnsupportedPlatform::notSixtyFourBit(PHP_INT_SIZE);
        }
    }
}
