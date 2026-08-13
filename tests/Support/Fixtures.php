<?php

declare(strict_types=1);

namespace Yjs\Tests\Support;

use RuntimeException;
use stdClass;
use Yjs\Binary\AnyValue\BigInt;
use Yjs\Binary\AnyValue\Bytes;
use Yjs\Binary\AnyValue\Undefined;

/**
 * Reads the committed Profile 1 fixtures and turns their tagged value specs
 * into PHP values.
 *
 * This is the PHP half of tools/oracle/spec.mjs. The two files have to agree on
 * what each tag means, which is why the tag set is small and closed.
 */
final class Fixtures
{
    private function __construct() {}

    public static function path(string $name): string
    {
        return __DIR__.'/../../fixtures/profile-1/'.$name.'.json';
    }

    /**
     * @return array<string, mixed>
     */
    public static function load(string $name): array
    {
        $path = self::path($name);
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Missing fixture file: {$path}. Run `npm --prefix tools/oracle install && composer fixtures`.");
        }

        return json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Every case in a fixture group, keyed by case name so a failure names the
     * value that broke rather than an index.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function cases(string $name): array
    {
        $cases = [];

        foreach (self::load($name)['cases'] as $case) {
            $cases[$case['name']] = $case;
        }

        return $cases;
    }

    public static function bytes(array $case): string
    {
        return base64_decode($case['bytes'], strict: true);
    }

    /**
     * Realize a tagged spec into the PHP value it names.
     */
    public static function realize(array $spec): mixed
    {
        return match ($spec['t']) {
            'undefined' => Undefined::instance(),
            'null' => null,
            'bool' => $spec['v'],
            'int' => (int) $spec['v'],
            'double' => unpack('E', hex2bin($spec['bits']))[1],
            'bigint' => new BigInt((int) $spec['v']),
            'string' => $spec['v'],
            'bytes' => new Bytes(base64_decode($spec['v'], strict: true)),
            'array' => array_map(self::realize(...), $spec['v']),
            'object' => self::realizeObject($spec['v']),
            default => throw new RuntimeException("Unknown fixture spec type: {$spec['t']}"),
        };
    }

    /**
     * The scalar behind a spec, for the fixture groups whose writer takes a raw
     * value rather than an "any" — `writeBigInt64` wants an int, not a wrapper.
     */
    public static function unwrap(array $spec): mixed
    {
        $value = self::realize($spec);

        return match (true) {
            $value instanceof BigInt => $value->value,
            $value instanceof Bytes => $value->bytes,
            default => $value,
        };
    }

    /**
     * @param  list<array{0: string, 1: array}>  $entries
     */
    private static function realizeObject(array $entries): stdClass
    {
        $object = new stdClass;

        foreach ($entries as [$key, $value]) {
            $object->{$key} = self::realize($value);
        }

        return $object;
    }
}
