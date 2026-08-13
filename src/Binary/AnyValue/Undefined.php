<?php

declare(strict_types=1);

namespace Yjs\Binary\AnyValue;

/**
 * JavaScript's `undefined`, which PHP has no value for.
 *
 * PHP's `null` already stands in for JavaScript's `null`, and the two are
 * distinct tags on the wire. Collapsing them would make a decoded update
 * re-encode to different bytes.
 */
final class Undefined
{
    private static ?self $instance = null;

    private function __construct() {}

    public static function instance(): self
    {
        return self::$instance ??= new self;
    }

    public function __toString(): string
    {
        return 'undefined';
    }
}
