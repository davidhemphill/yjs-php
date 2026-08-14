<?php

declare(strict_types=1);

namespace Hemp\Yjs\Exception;

use RuntimeException;

/**
 * Base class for every failure that occurs while reading untrusted bytes.
 *
 * Decoding is the library's only attack surface that consumes remote input, so
 * every failure below it must be a typed exception rather than a warning, a
 * silent truncation, or an unbounded allocation.
 */
class DecodeException extends RuntimeException implements YjsException {}
