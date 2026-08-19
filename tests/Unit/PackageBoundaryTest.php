<?php

declare(strict_types=1);

/**
 * The package boundary, asserted rather than promised.
 *
 * The whole point of this library is that a production collaboration server can
 * run on PHP alone. A stray dependency on a Node process, an FFI binding, or a
 * codec sidecar would defeat that quietly — everything would still pass, and the
 * deployment requirement would have changed without anyone deciding to change
 * it. These tests are what make that a build failure instead.
 */
function packageRoot(): string
{
    return dirname(__DIR__, 2);
}

/**
 * @return list<string>
 */
function sourceFiles(): array
{
    $files = [];

    $tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(packageRoot().'/src'));

    foreach ($tree as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

it('requires nothing but PHP at runtime', function () {
    $composer = json_decode(file_get_contents(packageRoot().'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    // Platform requirements are PHP itself: a version, and extensions the
    // runtime ships with. A Composer package is the thing that would change
    // what has to be installed to deploy this, so that is what is forbidden.
    $packages = array_values(array_filter(
        array_keys($composer['require']),
        fn (string $required): bool => $required !== 'php' && ! str_starts_with($required, 'ext-'),
    ));

    expect($packages)->toBe([], 'The package acquired a Composer dependency.')
        ->and($composer['require']['php'])->toBe('^8.4');
});

it('declares every extension its source actually uses', function () {
    $composer = json_decode(file_get_contents(packageRoot().'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    // An undeclared extension is a deployment requirement nobody decided on:
    // the install succeeds and the failure arrives later, from a socket.
    $prefixes = ['mb_' => 'ext-mbstring'];

    foreach (sourceFiles() as $file) {
        $source = file_get_contents($file);

        foreach ($prefixes as $prefix => $extension) {
            if (str_contains($source, $prefix)) {
                expect(array_key_exists($extension, $composer['require']))->toBeTrue(
                    "{$file} calls {$prefix}* but composer.json does not require {$extension}.",
                );
            }
        }
    }
});

it('reaches for no runtime outside PHP itself', function (string $file) {
    $source = file_get_contents($file);

    // Every escape hatch that could reintroduce a JavaScript runtime, a native
    // codec, or an out-of-process helper.
    $forbidden = ['FFI', 'proc_open', 'shell_exec', 'passthru', 'popen', 'exec(', 'system(', 'dl('];

    foreach ($forbidden as $needle) {
        expect(str_contains($source, $needle))->toBeFalse("{$file} references {$needle}.");
    }
})->with(fn () => array_map(fn (string $file) => [$file], sourceFiles()));

it('imports only its own namespace and PHP built-ins', function (string $file) {
    preg_match_all('/^use\s+([^\s;]+)\s*;/m', file_get_contents($file), $matches);

    // Anything PHP ships lives in the global namespace; anything Composer
    // installs is required by PSR-4 to sit under a vendor namespace. So an
    // import that contains a separator and is not ours came from a package,
    // which is the thing this package must not acquire.
    $foreign = array_values(array_filter($matches[1], fn (string $imported): bool => ! str_starts_with($imported, 'Hemp\Yjs\\')
        && (str_contains($imported, '\\') || ! (class_exists($imported) || interface_exists($imported)))));

    expect($foreign)->toBe([], "{$file} imports something that is neither ours nor a PHP built-in.");
})->with(fn () => array_map(fn (string $file) => [$file], sourceFiles()));

it('keeps the oracle out of the shipped package', function () {
    // The JavaScript oracle is a development tool. If it ever became reachable
    // from src/, the "no Node in production" property would be gone.
    foreach (sourceFiles() as $file) {
        expect(str_contains(file_get_contents($file), 'tools/oracle'))->toBeFalse();
    }

    expect(is_dir(packageRoot().'/tools/oracle'))->toBeTrue();
});
