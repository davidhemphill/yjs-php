<?php

declare(strict_types=1);

namespace Hemp\Yjs\Id;

use Hemp\Yjs\Binary\Decoder;
use Hemp\Yjs\Binary\Encoder;
use Hemp\Yjs\Binary\SafeInteger;

/**
 * Which clocks have been deleted, per client, as compact ranges.
 *
 * Deletions in Yjs are tombstones rather than removals, so this grows for the
 * life of a document and is the one structure guaranteed to keep getting
 * bigger. Keeping it as ranges is not an optimization, it is the reason a long
 * lived document stays tractable at all.
 */
final class DeleteSet
{
    /**
     * @param  array<int, list<ClockRange>>  $ranges  Client to its deleted runs.
     */
    private function __construct(private readonly array $ranges) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param  array<int, list<ClockRange>>  $ranges
     */
    public static function fromArray(array $ranges): self
    {
        $validated = [];

        foreach ($ranges as $client => $clientRanges) {
            $validated[SafeInteger::assertNonNegative($client)] = array_values($clientRanges);
        }

        return new self($validated);
    }

    /**
     * Yjs drops a client that declares zero deletions; we keep it.
     *
     * Nothing Yjs writes contains one, but a handmade or hostile update can, and
     * a relay that silently dropped the entry would re-encode to different bytes
     * than it received. {@see self::normalized()} removes them when the question
     * is what the set *means* rather than what it said.
     */
    public static function read(Decoder $decoder): self
    {
        // A client entry is at least two varUints: its ID and a zero count.
        $clientCount = $decoder->readCount(minimumBytesPerElement: 2);

        $ranges = [];

        for ($index = 0; $index < $clientCount; $index++) {
            $client = $decoder->readVarUint();
            // Each range is two varUints, so it cannot encode in under two bytes.
            $rangeCount = $decoder->readCount(minimumBytesPerElement: 2);

            $clientRanges = [];

            for ($rangeIndex = 0; $rangeIndex < $rangeCount; $rangeIndex++) {
                $clientRanges[] = new ClockRange($decoder->readVarUint(), $decoder->readVarUint());
            }

            $ranges[$client] = $clientRanges;
        }

        return new self($ranges);
    }

    public static function decode(string $bytes): self
    {
        return self::read(new Decoder($bytes));
    }

    /**
     * Written with clients in descending order, as Yjs writes them.
     */
    public function write(Encoder $encoder): Encoder
    {
        $ranges = $this->ranges;
        krsort($ranges, SORT_NUMERIC);

        $encoder->writeVarUint(count($ranges));

        foreach ($ranges as $client => $clientRanges) {
            $encoder->writeVarUint($client)->writeVarUint(count($clientRanges));

            foreach ($clientRanges as $range) {
                $encoder->writeVarUint($range->clock)->writeVarUint($range->length);
            }
        }

        return $encoder;
    }

    public function encode(): string
    {
        return $this->write(new Encoder)->toBytes();
    }

    /**
     * @return list<ClockRange>
     */
    public function rangesFor(int $client): array
    {
        return $this->ranges[$client] ?? [];
    }

    /**
     * @return array<int, list<ClockRange>>
     */
    public function toArray(): array
    {
        return $this->ranges;
    }

    /**
     * @return list<int>
     */
    public function clients(): array
    {
        return array_map(intval(...), array_keys($this->ranges));
    }

    public function isEmpty(): bool
    {
        foreach ($this->ranges as $clientRanges) {
            foreach ($clientRanges as $range) {
                if (! $range->isEmpty()) {
                    return false;
                }
            }
        }

        return true;
    }

    public function deletes(int $client, int $clock): bool
    {
        foreach ($this->rangesFor($client) as $range) {
            if ($range->contains($clock)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The canonical form: ranges sorted and coalesced, empties dropped.
     *
     * Two delete sets describing the same deletions can arrive shaped
     * differently — split at different points, listed in a different order —
     * and only their normalized forms can be compared.
     */
    public function normalized(): self
    {
        $normalized = [];

        foreach ($this->ranges as $client => $clientRanges) {
            $coalesced = self::coalesce($clientRanges);

            if ($coalesced !== []) {
                $normalized[$client] = $coalesced;
            }
        }

        // Descending, matching the order Yjs writes clients in, so that a
        // normalized set encodes to canonical bytes.
        krsort($normalized, SORT_NUMERIC);

        return new self($normalized);
    }

    /**
     * Combine delete sets the way `mergeUpdates` does.
     *
     * Distinct from {@see self::union()} in what it leaves alone: a client that
     * appears with no ranges stays, and a zero-length range survives the
     * coalescing pass. Yjs keeps both, so a merge that dropped them would not
     * reproduce its bytes. {@see self::normalized()} is the form to reach for
     * when the question is what the set means rather than what it said.
     */
    public static function mergedFrom(self ...$sets): self
    {
        $combined = [];

        foreach ($sets as $set) {
            foreach ($set->ranges as $client => $clientRanges) {
                $combined[$client] = [...($combined[$client] ?? []), ...$clientRanges];
            }
        }

        return new self(array_map(self::sortAndCoalesce(...), $combined));
    }

    /**
     * Yjs's `sortAndMergeDeleteSet`: sort by clock, then fold a range into its
     * predecessor whenever they touch or overlap.
     *
     * @param  list<ClockRange>  $ranges
     * @return list<ClockRange>
     */
    private static function sortAndCoalesce(array $ranges): array
    {
        usort($ranges, fn (ClockRange $left, ClockRange $right) => $left->clock <=> $right->clock);

        $coalesced = [];

        foreach ($ranges as $range) {
            $last = array_key_last($coalesced);

            if ($last !== null && $coalesced[$last]->end() >= $range->clock) {
                $coalesced[$last] = $coalesced[$last]->merge($range);

                continue;
            }

            $coalesced[] = $range;
        }

        return $coalesced;
    }

    /**
     * Everything deleted in either set.
     */
    public function union(self $other): self
    {
        $combined = $this->ranges;

        foreach ($other->ranges as $client => $clientRanges) {
            $combined[$client] = [...($combined[$client] ?? []), ...$clientRanges];
        }

        return (new self($combined))->normalized();
    }

    /**
     * Whether everything this set deletes, the other one deletes too.
     */
    public function isSubsetOf(self $other): bool
    {
        $normalizedOther = $other->normalized();

        foreach ($this->normalized()->ranges as $client => $clientRanges) {
            foreach ($clientRanges as $range) {
                if (! self::covers($normalizedOther->rangesFor($client), $range)) {
                    return false;
                }
            }
        }

        return true;
    }

    public function equals(self $other): bool
    {
        return $this->normalized()->encode() === $other->normalized()->encode();
    }

    /**
     * Total number of deleted clocks, for growth metrics.
     */
    public function deletedClockCount(): int
    {
        $total = 0;

        foreach ($this->normalized()->ranges as $clientRanges) {
            foreach ($clientRanges as $range) {
                $total += $range->length;
            }
        }

        return $total;
    }

    /**
     * Sort by clock and fold every touching pair into one range.
     *
     * @param  list<ClockRange>  $ranges
     * @return list<ClockRange>
     */
    private static function coalesce(array $ranges): array
    {
        $ranges = array_values(array_filter($ranges, fn (ClockRange $range) => ! $range->isEmpty()));

        usort($ranges, fn (ClockRange $left, ClockRange $right) => $left->clock <=> $right->clock);

        $coalesced = [];

        foreach ($ranges as $range) {
            $last = array_key_last($coalesced);

            if ($last !== null && $coalesced[$last]->touches($range)) {
                $coalesced[$last] = $coalesced[$last]->merge($range);

                continue;
            }

            $coalesced[] = $range;
        }

        return $coalesced;
    }

    /**
     * @param  list<ClockRange>  $ranges  Already normalized.
     */
    private static function covers(array $ranges, ClockRange $needle): bool
    {
        foreach ($ranges as $range) {
            if ($range->containsRange($needle)) {
                return true;
            }
        }

        return false;
    }
}
