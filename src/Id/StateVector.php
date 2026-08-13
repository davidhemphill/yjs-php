<?php

declare(strict_types=1);

namespace Yjs\Id;

use Yjs\Binary\Decoder;
use Yjs\Binary\Encoder;
use Yjs\Binary\SafeInteger;

/**
 * How much of every client's history a peer already has: client to next
 * expected clock.
 *
 * This is the whole of what one side tells the other during a sync. Everything
 * the update algebra does — deciding what is missing, what is redundant, what
 * to send — is a comparison of these.
 */
final class StateVector
{
    /**
     * @param  array<int, int>  $clocks  Client to next expected clock.
     */
    private function __construct(private readonly array $clocks) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param  array<int, int>  $clocks
     */
    public static function fromArray(array $clocks): self
    {
        $validated = [];

        foreach ($clocks as $client => $clock) {
            $validated[SafeInteger::assertNonNegative($client)] = SafeInteger::assertNonNegative($clock);
        }

        return new self($validated);
    }

    public static function read(Decoder $decoder): self
    {
        // Each entry is two varUints, so it cannot encode in under two bytes.
        $count = $decoder->readCount(minimumBytesPerElement: 2);

        $clocks = [];

        for ($index = 0; $index < $count; $index++) {
            $client = $decoder->readVarUint();
            $clocks[$client] = $decoder->readVarUint();
        }

        return new self($clocks);
    }

    public static function decode(string $bytes): self
    {
        return self::read(new Decoder($bytes));
    }

    /**
     * Yjs sorts by client descending before writing. Matching that is what lets
     * two peers that agree on the state produce identical bytes.
     */
    public function write(Encoder $encoder): Encoder
    {
        $clocks = $this->clocks;
        krsort($clocks, SORT_NUMERIC);

        $encoder->writeVarUint(count($clocks));

        foreach ($clocks as $client => $clock) {
            $encoder->writeVarUint($client)->writeVarUint($clock);
        }

        return $encoder;
    }

    public function encode(): string
    {
        return $this->write(new Encoder)->toBytes();
    }

    /**
     * The next clock expected from a client — zero when that client is unknown,
     * which is the same statement as "we have none of its history".
     */
    public function clockFor(int $client): int
    {
        return $this->clocks[$client] ?? 0;
    }

    public function knows(int $client): bool
    {
        return isset($this->clocks[$client]);
    }

    /**
     * @return array<int, int>
     */
    public function toArray(): array
    {
        return $this->clocks;
    }

    public function clientCount(): int
    {
        return count($this->clocks);
    }

    public function isEmpty(): bool
    {
        return $this->clocks === [];
    }

    public function with(int $client, int $clock): self
    {
        $clocks = $this->clocks;
        $clocks[SafeInteger::assertNonNegative($client)] = SafeInteger::assertNonNegative($clock);

        return new self($clocks);
    }

    /**
     * Raise a client's clock only if the new value is further along.
     */
    public function raisedTo(int $client, int $clock): self
    {
        return $clock > $this->clockFor($client) ? $this->with($client, $clock) : $this;
    }

    /**
     * The furthest either vector has reached, per client.
     */
    public function merge(self $other): self
    {
        $merged = $this;

        foreach ($other->clocks as $client => $clock) {
            $merged = $merged->raisedTo($client, $clock);
        }

        return $merged;
    }

    /**
     * Whether this vector has nothing the other is missing.
     */
    public function isCoveredBy(self $other): bool
    {
        foreach ($this->clocks as $client => $clock) {
            if ($other->clockFor($client) < $clock) {
                return false;
            }
        }

        return true;
    }

    public function equals(self $other): bool
    {
        return $this->isCoveredBy($other) && $other->isCoveredBy($this);
    }
}
