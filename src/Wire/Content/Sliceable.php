<?php

declare(strict_types=1);

namespace Hemp\Yjs\Wire\Content;

/**
 * Content that spans more than one clock and can therefore be split.
 *
 * Only four of the nine content types occupy more than a single clock, and only
 * those can ever be cut: a merge that resolves overlapping structs, or a diff
 * that starts partway through one, has to divide the payload at a clock
 * boundary. The other five are one clock each, so no offset inside them exists
 * to split at, and Yjs's own `splice` throws for exactly that reason.
 */
interface Sliceable extends Content
{
    /**
     * Divide the content at an offset measured in clocks.
     *
     * @return array{0: Content, 1: Content} The left and right halves, whose
     *                                       lengths sum to this content's length.
     */
    public function split(int $offset): array;
}
