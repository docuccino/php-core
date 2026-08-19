<?php

declare(strict_types=1);

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Pipeline\FragmentCache;
use Docuccino\Core\Pipeline\OperationFragment;

/**
 * A restored fragment has to hold the values the build put into it, and JSON is the storage. PHP's
 * defaults are lossy about floats in two ways: a whole one loses its fraction and reads back an int,
 * and the rest are formatted to whatever `serialize_precision` the host is set to. Either way a warm
 * build says something a cold one does not.
 */

/**
 * Round-trip one component schema through a cache directory of this test's own, and hand back what
 * came out of it.
 *
 * @param  array<string, mixed>  $schema
 * @return array<string, mixed>
 */
function fidelityRoundTrip(array $schema): array
{
    $directory = sys_get_temp_dir().'/docuccino-fidelity-'.bin2hex(random_bytes(6));

    try {
        $cache = static fn (): FragmentCache => new FragmentCache(true, $directory, 'tool', '1.0.0', 'v1');

        $cold = $cache();
        $key = $cold->key('GET /a', 'config', []);
        $cold->put($key, new OperationFragment(
            path: '/a',
            method: 'get',
            operation: (new OperationDraft)->freeze(),
            routeSignature: 'GET /a',
            componentSchemas: ['S' => $schema],
        ), []);

        $warm = $cache()->get($key);
        expect($warm)->not->toBeNull();

        /** @var OperationFragment $warm */
        return $warm->componentSchemas['S'];
    } finally {
        // Only files this test made, and only in the directory it made them in.
        array_map('unlink', glob($directory.'/*') ?: []);
        @rmdir($directory);
    }
}

it('restores a whole float as a float, not as the int it encodes to', function (): void {
    // `confidence: 1.0` on a provenance record is the one every build carries.
    $restored = fidelityRoundTrip(['x-docuccino' => ['provenance' => [['confidence' => 1.0]]]]);

    expect($restored)->toBe(['x-docuccino' => ['provenance' => [['confidence' => 1.0]]]]);
});

it('restores a float whatever serialize_precision the host is set to', function (string $precision): void {
    $original = ini_get('serialize_precision');
    ini_set('serialize_precision', $precision);

    try {
        expect(fidelityRoundTrip(['multipleOf' => 0.1234567890123456]))->toBe(['multipleOf' => 0.1234567890123456]);
    } finally {
        ini_set('serialize_precision', $original === false ? '-1' : $original);
    }
})->with([
    'shortest round-trip' => ['-1'],
    'seventeen significant digits' => ['17'],
    // The lossy one: left to itself `json_encode` would write 0.123456789 and the warm build would
    // document a different number from the cold one.
    'nine significant digits' => ['9'],
]);
