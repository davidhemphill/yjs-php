<?php

declare(strict_types=1);

namespace Yjs\Exception;

use RuntimeException;

/**
 * The PHP build cannot represent the values this library has to handle.
 */
final class UnsupportedPlatform extends RuntimeException implements YjsException
{
    public static function notSixtyFourBit(int $intSize): self
    {
        return new self(sprintf(
            'yjs-php requires a 64-bit PHP build; this one has %d-byte integers.',
            $intSize,
        ));
    }
}
