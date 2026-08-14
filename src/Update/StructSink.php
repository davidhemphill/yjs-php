<?php

declare(strict_types=1);

namespace Yjs\Update;

use Yjs\Wire\Struct;

/**
 * Collects merged structs into client sections, matching Yjs's
 * `LazyStructWriter`.
 *
 * A section is opened by the first struct of a client and closed as soon as a
 * struct for a different client arrives. The order sections come out in is the
 * order clients were first seen, which the merge arranges to be descending.
 */
final class StructSink
{
    /** @var list<ClientStructs> */
    private array $sections = [];

    /** @var list<Struct> */
    private array $open = [];

    private ?int $client = null;

    public function write(Struct $struct): void
    {
        if ($this->open !== [] && $this->client !== $struct->id()->client) {
            $this->flush();
        }

        $this->client = $struct->id()->client;

        // Yjs derives an Item's info byte at write time, so a merge normalizes
        // it. See Struct::normalized() for the bit this moves and why relaying
        // an update must not do the same.
        $this->open[] = $struct->normalized();
    }

    /**
     * @return list<ClientStructs>
     */
    public function sections(): array
    {
        $this->flush();

        return $this->sections;
    }

    private function flush(): void
    {
        if ($this->open === []) {
            return;
        }

        $this->sections[] = new ClientStructs(
            (int) $this->client,
            $this->open[0]->id()->clock,
            $this->open,
        );

        $this->open = [];
    }
}
