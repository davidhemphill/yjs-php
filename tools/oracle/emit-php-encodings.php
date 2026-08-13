<?php

declare(strict_types=1);

/**
 * Encode every fixture case with the PHP encoder and print the results as JSON.
 *
 * The committed fixtures prove PHP agrees with lib0 in one direction. This
 * feeds the other: verify-php-output.mjs takes these bytes and decodes them
 * with the real lib0 build, so that an error which happens to be symmetric in
 * PHP still gets caught.
 */

use Yjs\Binary\Encoder;
use Yjs\Tests\Support\Fixtures;
use Yjs\Tests\Support\PrimitiveGroups;

require __DIR__.'/../../vendor/autoload.php';

$groups = [];

foreach (PrimitiveGroups::all() as $group => $writer) {
    $cases = [];

    foreach (Fixtures::cases($group) as $name => $case) {
        $encoder = new Encoder;
        ($writer['encode'])($encoder, PrimitiveGroups::valueFor($group, $case));

        $cases[] = [
            'name' => $name,
            'value' => $case['value'],
            'bytes' => base64_encode($encoder->toBytes()),
        ];
    }

    $groups[$group] = $cases;
}

echo json_encode([
    'php' => PHP_VERSION,
    'intSize' => PHP_INT_SIZE,
    'groups' => $groups,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
