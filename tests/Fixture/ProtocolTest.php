<?php

declare(strict_types=1);

use Hemp\Yjs\Binary\DecodeLimits;
use Hemp\Yjs\Binary\Encoder;
use Hemp\Yjs\Protocol\Awareness\AwarenessEntry;
use Hemp\Yjs\Protocol\Awareness\AwarenessUpdate;
use Hemp\Yjs\Protocol\Sync\SyncMessageReader;
use Hemp\Yjs\Protocol\Sync\SyncMessageType;
use Hemp\Yjs\Protocol\Sync\SyncStep1;
use Hemp\Yjs\Protocol\Sync\SyncStep2;
use Hemp\Yjs\Protocol\Sync\SyncUpdate;
use Hemp\Yjs\Tests\Support\Fixtures;

/**
 * The y-protocols codecs, checked against traffic produced by the real
 * y-protocols rather than against a reading of it.
 */
$protocol = Fixtures::load('protocol');

$syncCases = array_map(fn (array $case) => [$case], $protocol['sync']);
$awarenessCases = array_map(fn (array $case) => [$case], $protocol['awareness']);

/** Look a transcript up by name, since the tests below each want one specific frame. */
$named = function (array $cases, string $name): array {
    foreach ($cases as $case) {
        if ($case['name'] === $name) {
            return $case;
        }
    }

    throw new RuntimeException("No protocol fixture named {$name}.");
};

$sync = fn (string $name) => $named($protocol['sync'], $name);
$awareness = fn (string $name) => $named($protocol['awareness'], $name);

describe('sync messages', function () use ($syncCases, $sync) {
    it('decodes what y-protocols wrote', function (array $case) {
        $bytes = base64_decode($case['bytes'], strict: true);

        // The two-message frame is the one case that is not a single message;
        // it has its own test below.
        if ($case['name'] === 'two-messages') {
            expect(SyncMessageReader::decodeAll($bytes, DecodeLimits::trusted()))->toHaveCount(2);

            return;
        }

        $message = SyncMessageReader::decode($bytes, DecodeLimits::trusted());

        expect($message->type())->toBeInstanceOf(SyncMessageType::class);
    })->with($syncCases);

    it('re-encodes to the identical bytes', function (array $case) {
        $bytes = base64_decode($case['bytes'], strict: true);

        if ($case['name'] === 'two-messages') {
            $encoder = new Encoder;

            foreach (SyncMessageReader::decodeAll($bytes, DecodeLimits::trusted()) as $message) {
                $message->write($encoder);
            }

            expect($encoder->toBytes())->toBeBytes($bytes);

            return;
        }

        expect(SyncMessageReader::decode($bytes, DecodeLimits::trusted())->encode())->toBeBytes($bytes);
    })->with($syncCases);

    it('reads a step1 as a state vector', function () use ($sync) {
        $case = $sync('step1-populated');
        $message = SyncMessageReader::decode(base64_decode($case['bytes'], strict: true), DecodeLimits::trusted());

        expect($message)->toBeInstanceOf(SyncStep1::class)
            ->and($message->stateVector->clientCount())->toBe(1);
    });

    it('reads a step2 as an update it can decode', function () use ($sync) {
        $case = $sync('step2-full');
        $message = SyncMessageReader::decode(base64_decode($case['bytes'], strict: true), DecodeLimits::trusted());

        expect($message)->toBeInstanceOf(SyncStep2::class)
            ->and($message->update(DecodeLimits::trusted())->structCount())->toBeGreaterThan(0);
    });

    it('distinguishes an update from a step2 carrying the same payload', function () use ($sync) {
        $step2 = $sync('step2-full');
        $update = $sync('update');

        $decodedStep2 = SyncMessageReader::decode(base64_decode($step2['bytes'], strict: true), DecodeLimits::trusted());
        $decodedUpdate = SyncMessageReader::decode(base64_decode($update['bytes'], strict: true), DecodeLimits::trusted());

        expect($decodedStep2)->toBeInstanceOf(SyncStep2::class)
            ->and($decodedUpdate)->toBeInstanceOf(SyncUpdate::class)
            ->and($decodedStep2->updateBytes)->toBe($decodedUpdate->updateBytes);
    });

    it('reads several messages out of one frame', function () use ($sync) {
        // A provider may pack a handshake and a first update together, so a
        // reader that assumed one message per frame would silently drop the
        // second.
        $case = $sync('two-messages');

        $messages = SyncMessageReader::decodeAll(
            base64_decode($case['bytes'], strict: true),
            DecodeLimits::trusted(),
        );

        expect($messages[0])->toBeInstanceOf(SyncStep1::class)
            ->and($messages[1])->toBeInstanceOf(SyncUpdate::class);
    });
});

describe('awareness updates', function () use ($awarenessCases, $awareness) {
    it('decodes what y-protocols wrote', function (array $case) {
        $update = AwarenessUpdate::decode(base64_decode($case['bytes'], strict: true));

        expect($update->entries)->toHaveCount(count($case['clients']));

        foreach ($case['clients'] as $index => $expected) {
            $entry = $update->entries[$index];

            expect($entry->client)->toBe($expected['client'])
                ->and($entry->clock)->toBe($expected['clock'])
                ->and($entry->state)->toBe($expected['state']);
        }
    })->with($awarenessCases);

    it('re-encodes to the identical bytes', function (array $case) {
        $bytes = base64_decode($case['bytes'], strict: true);

        expect(AwarenessUpdate::decode($bytes)->encode())->toBeBytes($bytes);
    })->with($awarenessCases);

    it('reads a null state as a removal', function () use ($awareness) {
        $case = $awareness('removal');
        $update = AwarenessUpdate::decode(base64_decode($case['bytes'], strict: true));

        expect($update->entries[0]->isRemoval())->toBeTrue()
            ->and($update->entries[0]->state)->toBeNull();
    });

    it('keeps presence and departure apart in one update', function () use ($awareness) {
        $case = $awareness('mixed');
        $update = AwarenessUpdate::decode(base64_decode($case['bytes'], strict: true));

        expect($update->entries[0]->isRemoval())->toBeFalse()
            ->and($update->entries[1]->isRemoval())->toBeTrue();
    });

    it('preserves state JSON verbatim rather than re-serializing it', function () use ($awareness) {
        // PHP's json_encode and JSON.stringify disagree about escaping and key
        // order, so anything but passing the text through unchanged would
        // produce different bytes for the same presence.
        $case = $awareness('several');
        $update = AwarenessUpdate::decode(base64_decode($case['bytes'], strict: true));

        expect($update->entries[2]->state)->toBe($case['clients'][2]['state'])
            ->and($update->entries[2]->state)->toContain('日本');
    });

    it('spells a removal as the JSON null document', function () {
        $bytes = (new AwarenessUpdate([AwarenessEntry::removal(9, 3)]))->encode();

        expect(AwarenessUpdate::decode($bytes)->entries[0]->isRemoval())->toBeTrue()
            ->and($bytes)->toContain('null');
    });
});
