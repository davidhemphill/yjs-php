<?php

declare(strict_types=1);

namespace Hemp\Yjs\Update;

use Hemp\Yjs\Id\DeleteSet;
use Hemp\Yjs\Id\Id;
use Hemp\Yjs\Wire\Gc;
use Hemp\Yjs\Wire\Skip;
use Hemp\Yjs\Wire\Struct;

/**
 * Combines updates into one, reproducing Yjs's `mergeUpdates`.
 *
 * Merging is not concatenation. Several updates routinely describe overlapping
 * runs of the same client's history, in pieces split at different points, and
 * the result has to be one ordered run per client with the overlaps resolved
 * and any remaining holes marked.
 *
 * ## Why this mirrors Yjs's structure rather than restating it
 *
 * The obvious implementation — collect every struct per client, sort by clock,
 * sweep once — converges correctly and was tried first. It produces different
 * *bytes*, because when two updates offer a different struct at the same clock
 * the choice is a tie that has to be broken somehow, and Yjs breaks it by
 * staying on the update it is already reading rather than by any property of
 * the structs themselves. Two guesses at an equivalent rule ("earlier update
 * wins", "longer struct wins") each matched some cases and inverted others.
 *
 * Both answers converge, so the plan's gate would have accepted either. But an
 * algebra that agrees with the reference implementation byte for byte is one
 * where a disagreement is a bug rather than a judgement call, and that is worth
 * more here than a shorter function — this is the phase where being subtly
 * wrong shows up weeks later as two clients disagreeing about the text.
 *
 * So the loop below follows Yjs's: a cursor per update, re-sorted each pass,
 * with one pending struct held back so the next one can be merged into it or
 * sliced against it.
 *
 * ## What deliberately does not happen
 *
 * Adjacent Items are never coalesced. `Item.mergeWith` requires
 * `this.right === right`, a link that only exists inside a materialized
 * document; structs decoded from an update always have a null right pointer, so
 * the check cannot pass and Yjs leaves adjacent Items separate too. GC and Skip
 * do coalesce, because their `mergeWith` looks only at the type.
 */
final class UpdateMerger
{
    private function __construct() {}

    public static function merge(Update ...$updates): Update
    {
        if ($updates === []) {
            return Update::empty();
        }

        $deleteSet = DeleteSet::mergedFrom(
            ...array_map(fn (Update $update) => $update->deleteSet, $updates),
        );

        return Update::of(self::mergeStructs($updates), $deleteSet);
    }

    /**
     * @param  list<Update>  $updates
     * @return list<ClientStructs>
     */
    private static function mergeStructs(array $updates): array
    {
        $cursors = array_map(fn (Update $update) => new StructCursor($update), $updates);
        $sink = new StructSink;

        /** The struct held back so the next one can merge into or slice against it. */
        $pending = null;

        while (true) {
            // The cursor list is sorted in place and kept, not rebuilt. Yjs
            // reassigns its array each pass, so the order established by one
            // sort survives into the next and breaks that pass's ties. Sorting
            // a fresh copy would reset those ties to the original update order
            // and pick a different struct.
            $cursors = array_values(array_filter(
                $cursors,
                fn (StructCursor $cursor) => $cursor->current() !== null,
            ));

            usort($cursors, self::byClientDescendingThenClock(...));

            if ($cursors === []) {
                break;
            }

            $cursor = $cursors[0];
            $client = $cursor->current()->id()->client;

            if ($pending === null) {
                $pending = $cursor->current();
                $cursor->next();
            } else {
                $resolved = self::advance($cursor, $pending, $client, $sink);

                // The cursor had nothing left to contribute against the pending
                // struct; leave it as it was and re-sort.
                if ($resolved === false) {
                    continue;
                }

                $pending = $resolved;
            }

            // Keep consuming this cursor while its structs run on contiguously.
            // This is the preference that decides every tie: once we are reading
            // an update, we keep reading it.
            for (
                $next = $cursor->current();
                $next !== null
                    && $next->id()->client === $client
                    && $next->id()->clock === self::end($pending)
                    && ! $next instanceof Skip;
                $next = $cursor->next()
            ) {
                $sink->write($pending);
                $pending = $next;
            }
        }

        if ($pending !== null) {
            $sink->write($pending);
        }

        return $sink->sections();
    }

    /**
     * Reconcile the cursor's current struct against the pending one.
     *
     * Returns the struct that becomes pending, or `false` when this pass
     * produced nothing and the cursors should simply be re-sorted.
     */
    private static function advance(StructCursor $cursor, Struct $pending, int $client, StructSink $sink): Struct|false
    {
        $current = $cursor->current();
        $iterated = false;

        // Walk past anything this cursor holds that the pending struct already
        // covers — the same operation arriving from a second update.
        while (
            $current !== null
            && self::end($current) <= self::end($pending)
            && $current->id()->client >= $pending->id()->client
        ) {
            $current = $cursor->next();
            $iterated = true;
        }

        if (
            $current === null
            || $current->id()->client !== $client
            || ($iterated && $current->id()->clock > self::end($pending))
        ) {
            return false;
        }

        if ($client !== $pending->id()->client) {
            $sink->write($pending);
            $cursor->next();

            return $current;
        }

        // A hole between the pending struct and this one.
        if (self::end($pending) < $current->id()->clock) {
            if ($pending instanceof Skip) {
                // Already marking a hole: widen it to cover this struct too.
                return new Skip($pending->id(), self::end($current) - $pending->id()->clock);
            }

            $sink->write($pending);

            return new Skip(
                new Id($client, self::end($pending)),
                $current->id()->clock - self::end($pending),
            );
        }

        // They overlap. Give up whichever part is already accounted for,
        // preferring to shorten a Skip since the other struct carries content.
        $overlap = self::end($pending) - $current->id()->clock;

        if ($overlap > 0) {
            if ($pending instanceof Skip) {
                $pending = new Skip($pending->id(), $pending->length() - $overlap);
            } else {
                $current = $current->sliceFrom($overlap);
            }
        }

        $coalesced = self::coalesce($pending, $current);

        if ($coalesced !== null) {
            return $coalesced;
        }

        $sink->write($pending);
        $cursor->next();

        return $current;
    }

    /**
     * Yjs's `mergeWith`, which succeeds only for two GCs or two Skips.
     */
    private static function coalesce(Struct $left, Struct $right): ?Struct
    {
        if ($left instanceof Gc && $right instanceof Gc) {
            return $left->extendedBy($right->length());
        }

        if ($left instanceof Skip && $right instanceof Skip) {
            return $left->extendedBy($right->length());
        }

        return null;
    }

    private static function end(Struct $struct): int
    {
        return $struct->id()->clock + $struct->length();
    }

    /**
     * Higher client IDs first, then by clock. Yjs writes updates in this order
     * because it makes its conflict resolution cheaper, and matching it is what
     * keeps merged output canonical.
     */
    private static function byClientDescendingThenClock(StructCursor $left, StructCursor $right): int
    {
        $a = $left->current();
        $b = $right->current();

        if ($a->id()->client === $b->id()->client) {
            if ($a->id()->clock !== $b->id()->clock) {
                return $a->id()->clock <=> $b->id()->clock;
            }

            // Same client and clock. Yjs orders a Skip last here and otherwise
            // returns -1 for any two different kinds, which is not a consistent
            // comparator — but reproducing it is the point, and skips are
            // filtered out before they reach this anyway.
            return $a::class === $b::class ? 0 : ($a instanceof Skip ? 1 : -1);
        }

        return $b->id()->client <=> $a->id()->client;
    }
}
