<?php

declare(strict_types=1);

namespace Yjs\Debug;

use Yjs\Update\ClientStructs;
use Yjs\Update\Update;
use Yjs\Wire\Content\AnyValues;
use Yjs\Wire\Content\Binary;
use Yjs\Wire\Content\Content;
use Yjs\Wire\Content\Deleted;
use Yjs\Wire\Content\Embed;
use Yjs\Wire\Content\Format;
use Yjs\Wire\Content\Json;
use Yjs\Wire\Content\SharedType;
use Yjs\Wire\Content\SubDocument;
use Yjs\Wire\Content\Text;
use Yjs\Wire\Gc;
use Yjs\Wire\Item;
use Yjs\Wire\Skip;
use Yjs\Wire\Struct;

/**
 * A readable rendering of a decoded update.
 *
 * When a fixture disagrees, the question is which struct and which field, and
 * a 4KB hex diff does not answer it. This does, without needing Node to find
 * out — which matters because the whole point of committing fixtures is that
 * the PHP suite runs on its own.
 */
final class UpdateDump
{
    private function __construct() {}

    public static function json(Update $update, bool $pretty = true): string
    {
        return CanonicalJson::encode(self::of($update), $pretty);
    }

    /**
     * @return array<string, mixed>
     */
    public static function of(Update $update): array
    {
        return [
            'sections' => array_map(self::section(...), $update->sections),
            'deleteSet' => array_map(
                fn (int $client) => [
                    'client' => $client,
                    'ranges' => array_map(
                        fn ($range) => (string) $range,
                        $update->deleteSet->rangesFor($client),
                    ),
                ],
                $update->deleteSet->clients(),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function section(ClientStructs $section): array
    {
        return [
            'client' => $section->client,
            'clock' => $section->clock,
            'endClock' => $section->endClock(),
            'contiguousEndClock' => $section->contiguousEndClock(),
            'structs' => array_map(self::struct(...), $section->structs),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function struct(Struct $struct): array
    {
        $dump = [
            'kind' => match (true) {
                $struct instanceof Item => 'Item',
                $struct instanceof Gc => 'GC',
                $struct instanceof Skip => 'Skip',
                default => $struct::class,
            },
            'id' => (string) $struct->id(),
            'length' => $struct->length(),
        ];

        if (! $struct instanceof Item) {
            return $dump;
        }

        return [
            ...$dump,
            // The raw info byte, because the parentSub bit can be set without
            // the field being present and that asymmetry is exactly the kind of
            // thing someone is here to debug.
            'info' => sprintf('0b%08b', $struct->info),
            'origin' => $struct->origin === null ? null : (string) $struct->origin,
            'rightOrigin' => $struct->rightOrigin === null ? null : (string) $struct->rightOrigin,
            'parent' => $struct->parent === null ? null : (string) $struct->parent,
            'parentSub' => $struct->parentSub,
            'content' => self::content($struct->content),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function content(Content $content): array
    {
        $described = ['ref' => $content->ref(), 'length' => $content->length()];

        return [...$described, ...match (true) {
            $content instanceof Deleted => [],
            $content instanceof Json => ['documents' => $content->encoded],
            $content instanceof Binary => ['bytes' => bin2hex($content->bytes)],
            $content instanceof Text => ['text' => $content->text],
            $content instanceof Embed => ['embed' => $content->encoded],
            $content instanceof Format => ['key' => $content->key, 'value' => $content->encodedValue],
            $content instanceof SharedType => ['typeRef' => $content->typeRef, 'name' => $content->name],
            $content instanceof AnyValues => ['values' => $content->values],
            $content instanceof SubDocument => ['guid' => $content->guid, 'options' => $content->options],
            default => [],
        }];
    }
}
