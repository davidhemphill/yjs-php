<?php

declare(strict_types=1);

namespace Yjs\Update;

use Yjs\Id\StateVector;
use Yjs\Wire\Skip;
use Yjs\Wire\Struct;

/**
 * Reduces an update to the part a peer is missing, the way `diffUpdate` does.
 *
 * Given what the other side already has, drop everything it already has and
 * send the rest. This is what turns a full document state into a small sync
 * reply.
 *
 * ## Two things about this that look like bugs and are not
 *
 * Once a client has *any* struct worth sending, every remaining struct of that
 * client is sent unconditionally, without consulting the state vector again.
 * That is correct rather than lazy: the state vector is a prefix, so if the
 * peer is missing something at clock N it is missing everything after N too,
 * and re-checking could only ever confirm that.
 *
 * The delete set is copied whole rather than filtered. A state vector says
 * nothing about deletions — it counts a client's structs, and a deletion is not
 * a struct — so there is no way to tell which tombstones the peer has. Sending
 * them all is cheap and idempotent, and losing one would resurrect deleted text.
 */
final class UpdateDiffer
{
    private function __construct() {}

    public static function diff(Update $update, StateVector $have): Update
    {
        $sections = [];

        foreach ($update->sections as $section) {
            $structs = self::diffSection($section, $have->clockFor($section->client));

            if ($structs !== []) {
                $sections[] = new ClientStructs($section->client, $structs[0]->id()->clock, $structs);
            }
        }

        return Update::of($sections, $update->deleteSet);
    }

    /**
     * @return list<Struct>
     */
    private static function diffSection(ClientStructs $section, int $known): array
    {
        $kept = [];

        foreach ($section->structs as $struct) {
            // Once something has been kept, the rest of this client follows it
            // regardless — see the note above.
            if ($kept !== []) {
                $kept[] = $struct->normalized();

                continue;
            }

            // A leading Skip says the sender had a hole here. It carries
            // nothing to send and must not become the first struct written,
            // since the section's starting clock comes from that struct.
            if ($struct instanceof Skip) {
                continue;
            }

            $end = $struct->id()->clock + $struct->length();

            if ($end <= $known) {
                continue;
            }

            $offset = max($known - $struct->id()->clock, 0);

            // A diff rebuilds the update, so its structs are normalized the same
            // way a merge's are.
            $kept[] = ($offset > 0 ? $struct->sliceFrom($offset) : $struct)->normalized();
        }

        return $kept;
    }
}
