<?php

declare(strict_types=1);

namespace Yjs\Exception;

use InvalidArgumentException;

/**
 * The caller asked the encoder to write something the wire format cannot
 * represent. Unlike decode failures, these are always programming errors.
 */
class EncodeException extends InvalidArgumentException implements YjsException
{
    public static function unsupportedValue(mixed $value): self
    {
        return new self(sprintf(
            'Cannot encode a value of type %s as a lib0 "any" value.',
            get_debug_type($value),
        ));
    }

    public static function nonIntegralFloat(float $value): self
    {
        return new self(sprintf('Expected an integral value, got %s.', var_export($value, true)));
    }

    public static function invalidUtf8(): self
    {
        return new self('Cannot encode a string that is not valid UTF-8.');
    }
}
