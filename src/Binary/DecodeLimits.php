<?php

declare(strict_types=1);

namespace Yjs\Binary;

/**
 * Bounds applied to a single decode pass over untrusted bytes.
 *
 * Every limit is checked against a *declared* length or count before any
 * memory is reserved, so a malicious header costs the reader a comparison
 * rather than an allocation.
 */
final class DecodeLimits
{
    /**
     * @param  int  $maxByteLength  Largest single length-prefixed byte array or string.
     * @param  int  $maxElementCount  Largest declared element count for one collection.
     * @param  int  $maxDepth  Deepest nesting of lib0 "any" containers.
     * @param  int  $maxTotalAllocation  Cumulative budget for everything one decode pass materializes.
     */
    public function __construct(
        public int $maxByteLength = 16 * 1024 * 1024,
        public int $maxElementCount = 1_000_000,
        public int $maxDepth = 64,
        public int $maxTotalAllocation = 64 * 1024 * 1024,
    ) {}

    /**
     * Limits for input that has already been vouched for — our own encoder's
     * output in a round-trip test, or a blob loaded from our own database.
     *
     * Still finite: "trusted" means the source is known, not that a corrupt
     * row should be able to exhaust the process.
     */
    public static function trusted(): self
    {
        return new self(
            maxByteLength: 512 * 1024 * 1024,
            maxElementCount: 64_000_000,
            maxDepth: 1024,
            maxTotalAllocation: 1024 * 1024 * 1024,
        );
    }

    /**
     * Deliberately tight limits for tests that assert bounded failure.
     */
    public static function strict(): self
    {
        return new self(
            maxByteLength: 4096,
            maxElementCount: 256,
            maxDepth: 8,
            maxTotalAllocation: 65536,
        );
    }
}
