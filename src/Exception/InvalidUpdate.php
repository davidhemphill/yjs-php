<?php

declare(strict_types=1);

namespace Hemp\Yjs\Exception;

/**
 * A structurally decodable update that is not one we will accept.
 *
 * Deliberately a {@see DecodeException}: from a server's point of view this is
 * still "the peer sent something we are rejecting", and a connection handler
 * should be able to catch the whole category in one place rather than
 * remembering that validation lives outside it.
 */
final class InvalidUpdate extends DecodeException
{
    public static function nonContiguous(int $client, int $expected, int $found): self
    {
        return new self(sprintf(
            'Client %d has a struct at clock %d where %d was expected; structs in a section must run continuously.',
            $client,
            $found,
            $expected,
        ));
    }

    public static function sectionClockMismatch(int $client, int $declared, int $found): self
    {
        return new self(sprintf(
            'Client %d declares a starting clock of %d but its first struct is at %d.',
            $client,
            $declared,
            $found,
        ));
    }

    public static function limit(string $what, int $found, int $limit): self
    {
        return new self(sprintf('Update carries %s of %d, past the limit of %d.', $what, $found, $limit));
    }

    public static function duplicateClient(int $client): self
    {
        return new self(sprintf('Client %d appears in more than one section.', $client));
    }
}
