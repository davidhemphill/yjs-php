<?php

declare(strict_types=1);

namespace Yjs\Wire\Content;

use Yjs\Binary\Decoder;
use Yjs\Binary\Encoder;

/**
 * Content ref 2 — a list of values encoded as JSON text, one string each.
 *
 * The JSON is kept exactly as it arrived rather than parsed. `JSON.stringify`
 * and PHP's `json_encode` disagree about escaping, float formatting, and key
 * order, so parsing and re-serializing would produce different bytes for the
 * same value and break every byte-level comparison downstream. This library
 * does not need to know what the JSON means, so it does not look.
 *
 * The single exception is the literal string `undefined`, which Yjs writes in
 * place of a value JSON cannot represent. It is not valid JSON and is not meant
 * to be read as any.
 */
final class Json implements Sliceable
{
    /**
     * @return array{0: Content, 1: Content}
     */
    public function split(int $offset): array
    {
        return [
            new self(array_slice($this->encoded, 0, $offset)),
            new self(array_slice($this->encoded, $offset)),
        ];
    }

    /**
     * The marker Yjs writes for a value JSON has no syntax for.
     */
    public const string UNDEFINED = 'undefined';

    /**
     * @param  list<string>  $encoded  Raw JSON documents, verbatim from the wire.
     */
    public function __construct(public readonly array $encoded) {}

    public static function read(Decoder $decoder): self
    {
        // Each entry is a length-prefixed string, so at least one byte.
        $count = $decoder->readCount(minimumBytesPerElement: 1);

        $encoded = [];

        for ($index = 0; $index < $count; $index++) {
            $encoded[] = $decoder->readVarString();
        }

        return new self($encoded);
    }

    public function ref(): int
    {
        return 2;
    }

    public function length(): int
    {
        return count($this->encoded);
    }

    public function write(Encoder $encoder): void
    {
        $encoder->writeVarUint(count($this->encoded));

        foreach ($this->encoded as $document) {
            $encoder->writeVarString($document);
        }
    }

    /**
     * Whether the entry at this index is Yjs's `undefined` marker rather than a
     * JSON document.
     */
    public function isUndefined(int $index): bool
    {
        return ($this->encoded[$index] ?? null) === self::UNDEFINED;
    }
}
