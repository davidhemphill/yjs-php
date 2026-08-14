<?php

declare(strict_types=1);

namespace Hemp\Yjs\Exception;

use Throwable;

/**
 * Implemented by every exception this library raises, so a caller can catch
 * the whole library with a single catch block.
 */
interface YjsException extends Throwable {}
