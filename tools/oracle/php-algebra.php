<?php

declare(strict_types=1);

/**
 * Run the PHP update algebra over a batch of operations given as JSON on stdin.
 *
 * The differential oracle drives this. Operations are batched into one process
 * because a randomized run issues thousands of them, and paying PHP's startup
 * per operation would make the corpus small enough to miss things.
 *
 * Input:  {"ops": [{"id": 1, "op": "merge", "updates": ["<base64>", ...]}, ...]}
 * Output: {"results": [{"id": 1, "update": "<base64>"}, ...]}
 */

use Hemp\Yjs\Binary\DecodeLimits;
use Hemp\Yjs\Id\StateVector;
use Hemp\Yjs\Update\Update;

require __DIR__.'/../../vendor/autoload.php';

$limits = DecodeLimits::trusted();

/** @return Update */
$decode = fn (string $base64) => Update::decode(base64_decode($base64, strict: true), $limits);

$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);

$results = [];

foreach ($input['ops'] as $operation) {
    try {
        $results[] = ['id' => $operation['id'], ...match ($operation['op']) {
            'merge' => [
                'update' => base64_encode(
                    Update::mergeAll(...array_map($decode, $operation['updates']))->encode(),
                ),
            ],
            'diff' => [
                'update' => base64_encode(
                    $decode($operation['update'])
                        ->diff(StateVector::decode(base64_decode($operation['stateVector'], strict: true)))
                        ->encode(),
                ),
            ],
            'stateVector' => [
                'stateVector' => base64_encode($decode($operation['update'])->stateVector()->encode()),
            ],
            'contains' => [
                'contains' => $decode($operation['update'])->contains($decode($operation['candidate'])),
            ],
            default => throw new RuntimeException("Unknown operation: {$operation['op']}"),
        }];
    } catch (Throwable $failure) {
        $results[] = [
            'id' => $operation['id'],
            'error' => $failure::class.': '.$failure->getMessage(),
        ];
    }
}

echo json_encode(['results' => $results], JSON_THROW_ON_ERROR);
