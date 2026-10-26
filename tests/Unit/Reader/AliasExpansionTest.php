<?php

declare(strict_types=1);

use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Docuccino\Core\Identity\ContentHasher;
use Docuccino\Core\Reader\DocumentReader;
use Docuccino\Core\Reader\ReadFailure;
use Docuccino\Core\Reader\UnreadableDocumentException;
use Docuccino\Core\Support\JsonValue;
use Symfony\Component\Yaml\Yaml;

/*
 * YAML aliases are bounded by what they expand to, in values AND in the text those values hold. One long
 * string named again and again is one value a use, so a value count alone reads it in milliseconds and
 * leaves `json()` to write it out: 107 KB became 100 MB. Text is every string and member name, counted at
 * each place the expanded document holds it, and a document may hold eight bytes of it for every byte of
 * its own (1 MiB for a small one) before it is refused, while it is read and before anything walks it.
 */

$head = "openapi: 3.1.0\ninfo:\n  title: Shared\n  version: '1'\npaths: {}\n";

/** Nested lists, each naming the one below it $fan times, $levels deep over one $leaf. */
$laughs = static function (int $fan, int $levels, string $leaf) use ($head): string {
    $yaml = $head."x-l0: &l0 [{$leaf}]\n";
    foreach (range(1, $levels) as $level) {
        $yaml .= "x-l{$level}: &l{$level} [".implode(', ', array_fill(0, $fan, '*l'.($level - 1)))."]\n";
    }

    return $yaml;
};

it('refuses a document whose aliases repeat text far past its size, cheaply and before anything walks it', function (string $yaml, string $refusal): void {
    memory_reset_peak_usage();
    $before = memory_get_usage();
    $started = hrtime(true);

    try {
        (new DocumentReader)->read($yaml);
        $this->fail('A document expanding to gigabytes of text was read.');
    } catch (UnreadableDocumentException $exception) {
        $limit = $refusal === 'values' ? max(10_000, strlen($yaml)) : max(1_048_576, 8 * strlen($yaml));

        expect($exception->failure)->toBe(ReadFailure::AliasExpansion)
            ->and($exception->reason)->toContain(sprintf('expands to more than %d %s from %d bytes', $limit, $refusal, strlen($yaml)))
            ->and((hrtime(true) - $started) / 1e9)->toBeLessThan(1.0)
            // Written out, each of these is hundreds of megabytes at least.
            ->and(memory_get_peak_usage() - $before)->toBeLessThan(16 * 1024 * 1024);
    }
})->with([
    // `json()` wrote 100 MB of these 107 KB, at a 288 MB peak.
    'a 100 KB string named 1,000 times' => [$head.'x-text: &s '.str_repeat('x', 100_000)."\nx-copies:\n".str_repeat("  - *s\n", 1_000), 'bytes of text'],
    // And 10 GB of these 1.07 MB, which no memory limit survives.
    'a 1 MB string named 10,000 times' => [$head.'x-text: &s '.str_repeat('x', 1_000_000)."\nx-copies:\n".str_repeat("  - *s\n", 10_000), 'bytes of text'],
    'a map with a 100 KB member name, named 100 times' => [$head.'x-map: &m {'.str_repeat('k', 100_000).": 1}\nx-copies:\n".str_repeat("  - *m\n", 100), 'bytes of text'],
    'a map holding a 100 KB string, merged into 100 maps' => [$head.'x-map: &m {a: '.str_repeat('x', 100_000)."}\nx-merged:\n".implode('', array_map(static fn (int $i): string => "  m{$i}:\n    <<: *m\n", range(1, 100))), 'bytes of text'],
    // 11,111 values, inside the value budget of this 100 KB, holding 1 GB of text.
    'four levels of ten aliases over a 100 KB string' => [$laughs(10, 4, str_repeat('x', 100_000)), 'bytes of text'],
    // A date is one value and 32 bytes of text, so the text passes 1 MiB at half this 68 KB's value budget.
    'a list of 2,000 dates named 100 times' => [$head.'x-dates: &d ['.implode(', ', array_fill(0, 2_000, '2024-01-01T10:00:00.123456+02:00'))."]\nx-tens: &t [".implode(', ', array_fill(0, 10, '*d'))."]\nx-hundreds: [".implode(', ', array_fill(0, 10, '*t'))."]\n", 'bytes of text'],
    // A billion values from 625 bytes, which the value budget stops first.
    'nine levels of ten aliases over one letter' => [$laughs(10, 9, 'x'), 'values'],
]);

it('admits exactly the text its limit allows and refuses one byte more, in both regimes', function (int $length, int $uses, int $text, ?int $bytes, bool $admitted): void {
    // Three names, a string of $length bytes written once and named $uses more times, and a second string
    // padding the text to $text; a comment then pads the document to $bytes without adding any text.
    $yaml = 'x: &s '.str_repeat('a', $length)."\ny:\n".str_repeat("- *s\n", $uses).'z: '.str_repeat('b', $text - 3 - $length * (1 + $uses))."\n";
    if ($bytes !== null) {
        $yaml .= '#'.str_repeat(' ', $bytes - strlen($yaml) - 2)."\n";
    }
    expect(strlen($yaml))->toBe($bytes ?? strlen($yaml));

    try {
        $read = json_decode((new DocumentReader)->read($yaml)->json(), true, 512, JSON_THROW_ON_ERROR);
        expect($admitted)->toBeTrue()
            ->and($read['y'])->toHaveCount($uses)
            ->and(strlen(implode('', [...array_keys($read), ...$read['y'], $read['x'], $read['z']])))->toBe($text);
    } catch (UnreadableDocumentException $exception) {
        expect($admitted)->toBeFalse()
            ->and($exception->failure)->toBe(ReadFailure::AliasExpansion)
            ->and($exception->reason)->toContain(sprintf('more than %d bytes of text from %d bytes', $text - 1, strlen($yaml)));
    }
})->with([
    // Under 128 KiB of document, the floor is the limit: 1 MiB.
    'the floor, exactly' => [10_000, 100, 1_048_576, null, true],
    'the floor, one byte past' => [10_000, 100, 1_048_577, null, false],
    // Past it, eight bytes of text per byte: 1,600,000 for 200,000.
    'eight per byte, exactly' => [10_000, 150, 1_600_000, 200_000, true],
    'eight per byte, one byte past' => [10_000, 150, 1_600_001, 200_000, false],
]);

it('reads every document in the fixture trees to the graph its parser reads, json and content hash alike', function (): void {
    $documents = [
        ...uirDocuments(),
        ...(array) glob(dirname(__DIR__, 3).'/tests/Fixtures/golden/*.openapi3*.{json,yaml}', GLOB_BRACE),
    ];

    $yaml = 0;
    foreach ($documents as $path) {
        $bytes = (string) file_get_contents((string) $path);
        $parsed = str_ends_with((string) $path, '.json')
            ? json_decode($bytes, false, 512, JSON_THROW_ON_ERROR)
            : Yaml::parse($bytes, Yaml::PARSE_OBJECT_FOR_MAP | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        $yaml += str_ends_with((string) $path, '.yaml') ? 1 : 0;
        expect($parsed)->toBeInstanceOf(stdClass::class);

        $document = (new DocumentReader)->read($bytes);

        expect($document->json())->toBe((new CanonicalJsonSerializer)->serialize($parsed), (string) $path)
            ->and($document->contentHash())->toBe((new ContentHasher)->hash(array_map(JsonValue::normalize(...), get_object_vars($parsed))), (string) $path);
    }

    // A scan that found nothing would agree with itself forever.
    expect(count($documents))->toBeGreaterThan(40)
        ->and($yaml)->toBeGreaterThan(1);
});
