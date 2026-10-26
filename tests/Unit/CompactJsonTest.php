<?php

declare(strict_types=1);

use Docuccino\Core\Reader\DocumentDecoder;
use Docuccino\Core\Reader\DocumentReader;
use Docuccino\Core\Support\CompactJson;
use Docuccino\Core\Support\JsonValue;

/*
 * A document's size as compact JSON: the bytes of the JSON it is written back as, once the whitespace is
 * out — counted without writing them, and no further than a caller asks.
 */

it('counts the JSON a document is written back as without its whitespace, for every document in the fixture trees', function (): void {
    $documents = openApiDocuments();

    foreach ($documents as $path) {
        $json = (new DocumentReader)->read((string) file_get_contents($path))->json();
        // Whitespace outside strings, taken out by a reader that knows nothing of JSON but its strings.
        $compact = preg_replace('/"(?:[^"\\\\]++|\\\\.)*+"(*SKIP)(*FAIL)|\s++/', '', $json);
        expect($compact)->toBeString();

        [$graph] = (new DocumentDecoder)->decode($json, 'application/json');
        /** @var array<string, mixed> $document */
        $document = array_map(JsonValue::normalize(...), get_object_vars($graph));

        expect(CompactJson::length($graph))->toBe(strlen((string) $compact), $path)
            ->and(CompactJson::lengthUpTo($graph, PHP_INT_MAX))->toBe(strlen((string) $compact), $path)
            // The form the node index is counted from, where a map is an array.
            ->and(CompactJson::lengthUpTo($document, PHP_INT_MAX))->toBe(strlen((string) $compact), $path);
    }

    // A scan that found nothing would agree with itself forever.
    expect(count($documents))->toBeGreaterThan(90);
});

it('stops at the count it is asked for, and answers that count exactly', function (): void {
    $json = '{"a":[1,2.5,{}],"b":{"c":"d\"e","0":[],"f":null},"g":[[true,false]],"h":"/é\u2028"}';
    $value = JsonValue::decode($json);
    $length = CompactJson::length($value);

    // `/` and `é` as written, U+2028 escaped, as the JSON a document is written back as spells them.
    expect($length)->toBe(strlen('{"a":[1,2.5,{}],"b":{"c":"d\"e","0":[],"f":null},"g":[[true,false]],"h":"/é\u2028"}'));

    foreach (range(0, $length + 2) as $enough) {
        expect(CompactJson::lengthUpTo($value, $enough))->toBe(min($length, $enough), (string) $enough);
    }
});

it('counts a float as every host writes it, and leaves the host as it was', function (): void {
    $previous = ini_set('serialize_precision', '17');

    try {
        // At 17 digits `json_encode` writes 0.1 as 0.10000000000000001.
        expect(CompactJson::length([0.1]))->toBe(5)
            ->and(CompactJson::lengthUpTo([0.1], PHP_INT_MAX))->toBe(5)
            ->and(ini_get('serialize_precision'))->toBe('17');
    } finally {
        ini_set('serialize_precision', (string) $previous);
    }
});

it('reads no more of a string an alias repeats than the answer needs', function (): void {
    // One 1 MB string named 10,000 times: 10 GB written out, and seconds even to count by reading it all.
    [$graph] = (new DocumentDecoder)->decode("x-text: &text '".str_repeat('x', 1_000_000)."'\nx-again:\n".str_repeat("  - *text\n", 10_000), null);

    memory_reset_peak_usage();
    $before = memory_get_usage();
    $started = hrtime(true);

    expect(CompactJson::lengthUpTo($graph, 3_000_000))->toBe(3_000_000)
        ->and((hrtime(true) - $started) / 1e9)->toBeLessThan(1.0)
        ->and(memory_get_peak_usage() - $before)->toBeLessThan(16 * 1024 * 1024);
});
