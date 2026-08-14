<?php

declare(strict_types=1);

namespace Yjs\Update;

use Yjs\Exception\InvalidUpdate;

/**
 * Checks that a decoded update is one we are willing to work with.
 *
 * Two separate jobs, which are worth keeping distinct:
 *
 * The structural checks are invariants the format guarantees for anything read
 * off the wire — the decoder reconstructs clocks by accumulation, so a decoded
 * section is contiguous by construction. They matter for updates *built* rather
 * than read: the merge and diff paths assemble sections themselves, and a bug
 * there would produce an update that encodes cleanly and desynchronizes every
 * client that applies it. Cheap to check, and the failure is otherwise silent.
 *
 * The limit checks are policy, and belong to whoever is running the server.
 */
final class UpdateValidator
{
    private function __construct() {}

    /**
     * @throws InvalidUpdate
     */
    public static function validate(Update $update, ?SemanticLimits $limits = null): void
    {
        $limits ??= new SemanticLimits;

        self::checkStructure($update);
        self::checkLimits($update, $limits);
    }

    /**
     * @throws InvalidUpdate
     */
    private static function checkStructure(Update $update): void
    {
        $seen = [];

        foreach ($update->sections as $section) {
            if (isset($seen[$section->client])) {
                throw InvalidUpdate::duplicateClient($section->client);
            }

            $seen[$section->client] = true;

            if ($section->structs === []) {
                continue;
            }

            $first = $section->structs[0]->id()->clock;

            if ($first !== $section->clock) {
                throw InvalidUpdate::sectionClockMismatch($section->client, $section->clock, $first);
            }

            // Only the first clock is on the wire; every struct after it starts
            // where the previous one ended. A gap has to be an explicit Skip,
            // never an implied one.
            $expected = $section->clock;

            foreach ($section->structs as $struct) {
                if ($struct->id()->clock !== $expected) {
                    throw InvalidUpdate::nonContiguous($section->client, $expected, $struct->id()->clock);
                }

                if ($struct->id()->client !== $section->client) {
                    throw InvalidUpdate::duplicateClient($struct->id()->client);
                }

                $expected += $struct->length();
            }
        }
    }

    /**
     * @throws InvalidUpdate
     */
    private static function checkLimits(Update $update, SemanticLimits $limits): void
    {
        if (count($update->sections) > $limits->maxClients) {
            throw InvalidUpdate::limit('clients', count($update->sections), $limits->maxClients);
        }

        $structs = $update->structCount();

        if ($structs > $limits->maxStructs) {
            throw InvalidUpdate::limit('structs', $structs, $limits->maxStructs);
        }

        foreach ($update->structs() as $struct) {
            if ($struct->length() > $limits->maxStructLength) {
                throw InvalidUpdate::limit('a struct spanning clocks', $struct->length(), $limits->maxStructLength);
            }
        }

        $ranges = 0;

        foreach ($update->deleteSet->clients() as $client) {
            $ranges += count($update->deleteSet->rangesFor($client));
        }

        if ($ranges > $limits->maxDeleteRanges) {
            throw InvalidUpdate::limit('delete ranges', $ranges, $limits->maxDeleteRanges);
        }
    }
}
