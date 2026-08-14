<?php

declare(strict_types=1);

namespace Yjs\Update;

use Yjs\Binary\DecodeLimits;

/**
 * Bounds on what a decoded update is allowed to *be*, as opposed to how many
 * bytes it took to say it.
 *
 * {@see DecodeLimits} stops a hostile header from costing memory.
 * These are the limits above that: an update can be perfectly well formed,
 * decode within budget, and still be one no server should accept — a hundred
 * thousand clients, a single struct spanning a billion clocks, a delete set
 * with more ranges than the document has ever had characters.
 *
 * Defaults are generous enough that no real document reaches them, because a
 * limit that legitimate traffic trips is worse than no limit at all.
 */
final class SemanticLimits
{
    /**
     * @param  int  $maxClients  Distinct clients one update may carry.
     * @param  int  $maxStructs  Total structs across every client.
     * @param  int  $maxStructLength  Clocks a single struct may span.
     * @param  int  $maxDeleteRanges  Total ranges across the delete set.
     */
    public function __construct(
        public int $maxClients = 10_000,
        public int $maxStructs = 500_000,
        public int $maxStructLength = 1_000_000,
        public int $maxDeleteRanges = 500_000,
    ) {}

    /**
     * Deliberately tight limits, for tests that assert rejection.
     */
    public static function strict(): self
    {
        return new self(
            maxClients: 2,
            maxStructs: 8,
            maxStructLength: 16,
            maxDeleteRanges: 4,
        );
    }
}
