<?php

declare(strict_types=1);

use Yjs\Binary\DecodeLimits;
use Yjs\Debug\UpdateDump;
use Yjs\Tests\Support\Fixtures;
use Yjs\Update\Update;

$decode = fn (string $name) => Update::decode(
    base64_decode(Fixtures::cases('updates')[$name]['update'], strict: true),
    DecodeLimits::trusted(),
);

it('renders every fixture without failing', function (string $name) use ($decode) {
    // A debugging tool that throws while you are debugging is worse than none.
    $json = UpdateDump::json($decode($name));

    expect(json_decode($json, true, flags: JSON_THROW_ON_ERROR))->toBeArray();
})->with(array_keys(Fixtures::cases('updates')));

it('names the struct, its clock, and its content', function () use ($decode) {
    $dump = UpdateDump::of($decode('text-plain'));

    expect($dump['sections'][0]['structs'][0])
        ->toHaveKeys(['kind', 'id', 'length', 'info', 'origin', 'parent', 'content'])
        ->and($dump['sections'][0]['structs'][0]['kind'])->toBe('Item')
        ->and($dump['sections'][0]['structs'][0]['content']['ref'])->toBe(4)
        ->and($dump['sections'][0]['structs'][0]['content']['text'])->toBe('Hello 😀 world');
});

it('shows the info byte in a form that makes its bits readable', function () use ($decode) {
    $dump = UpdateDump::of($decode('text-formatted'));

    foreach ($dump['sections'][0]['structs'] as $struct) {
        expect($struct['info'])->toMatch('/^0b[01]{8}$/');
    }
});

it('distinguishes the three struct kinds', function () use ($decode) {
    $kinds = array_map(
        fn (array $struct) => $struct['kind'],
        UpdateDump::of($decode('skip-structs'))['sections'][0]['structs'],
    );

    expect($kinds)->toBe(['Item', 'Skip', 'Item']);
});

it('shows where a contiguous run stops', function () use ($decode) {
    $section = UpdateDump::of($decode('skip-structs'))['sections'][0];

    expect($section['endClock'])->toBe(18)
        ->and($section['contiguousEndClock'])->toBe(6);
});

it('renders the delete set as readable ranges', function () use ($decode) {
    $dump = UpdateDump::of($decode('deletes-across-clients'));

    expect($dump['deleteSet'])->not->toBeEmpty()
        ->and($dump['deleteSet'][0]['ranges'][0])->toMatch('/^\[\d+,\d+\)$/');
});
