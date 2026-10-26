<?php

declare(strict_types=1);

use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Docuccino\Core\Identity\ContentHasher;
use Docuccino\Core\Reader\DocumentReader;
use Docuccino\Core\Reader\ReadFailure;
use Docuccino\Core\Reader\Syntax;
use Docuccino\Core\Reader\UnreadableDocumentException;
use Docuccino\Core\Support\JsonValue;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/*
 * YAML aliases are bounded by what they expand to, in values AND in the text those values hold. One long
 * string named again and again is one value a use, so a value count alone reads it in milliseconds and
 * leaves `json()` to write it out: 107 KB became 100 MB. Text is every string and member name, counted at
 * each place the expanded document holds it, and a document may hold eight bytes of it for every byte of
 * its own (1 MiB for a small one) before it is refused, while it is read and before anything walks it.
 *
 * Those budgets, not the count of aliases, are what refuse a document. symfony/yaml stops at 128 aliases to
 * a map or a list on its own, which refused real descriptions that alias a shared response at every
 * operation, so the reader lifts that cap wherever the text cannot spell a merge key. Where it could, the cap
 * stays and shrinks as the document grows: a merge copies the map it names while the parser reads, before
 * any budget here can see it, so what merges cost is how many there are times the members each copies.
 */

$head = "openapi: 3.1.0\ninfo:\n  title: Shared\n  version: '1'\npaths: {}\n";

/**
 * The aliases to a map or a list a document may name once its text could spell a merge key, stated from the
 * rule rather than read off the reader: no map or list holds more members than the text has separators (a
 * comma, a bracket, a brace or a line break) plus one, and the merges times those members may not pass 2^19.
 */
$cap = static fn (string $yaml): int => min(128, intdiv(524_288, 1 + strlen($yaml) - strlen(str_replace([',', '[', ']', '{', '}', "\n", "\r"], '', $yaml))));

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

/*
 * The two shapes generators dump, each past the parser's own 128: PyYAML anchors a parameter list and each
 * response the first time it writes them (`&id001`) and names them at every operation after, and js-yaml
 * style dumps (`&ref_0`) name lists and maps, inside flow collections too. The twin is the same document
 * built as PHP values and encoded as JSON, so nothing in it came through a YAML parser.
 */
it('reads a document naming more aliases than the parser allows on its own, as the JSON it stands for', function (string $yaml, array $document): void {
    $read = (new DocumentReader)->read($yaml);
    $twin = (new DocumentReader)->read(json_encode($document, JSON_THROW_ON_ERROR));

    expect($read->inspect()->syntax)->toBe(Syntax::Yaml)
        ->and($read->json())->toBe($twin->json())
        ->and($read->contentHash())->toBe($twin->contentHash())
        ->and($read->nodes())->toEqual($twin->nodes());

    // A document the parser refuses when left to itself, so this proves the cap lifted rather than unmet.
    expect(fn (): mixed => Yaml::parse($yaml))->toThrow(ParseException::class, 'Maximum number of collection aliases (128) exceeded');
})->with(static function (): array {
    $parameters = [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]];
    $unauthorized = ['description' => 'Unauthorized', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['message' => ['type' => 'string']]]]]];
    $missing = ['description' => 'Not found'];

    // 99 operations naming three blocks the first one anchored: 297 aliases.
    $pyyaml = "openapi: 3.1.0\ninfo:\n  title: Orders\n  version: '1'\npaths:\n";
    $paths = [];
    foreach (range(0, 99) as $i) {
        $pyyaml .= $i === 0
            ? "  /orders/{id}/v0:\n    get:\n      parameters: &id001\n      - name: id\n        in: path\n        required: true\n        schema:\n          type: string\n      responses:\n        '401': &id002\n          description: Unauthorized\n          content:\n            application/json:\n              schema:\n                type: object\n                properties:\n                  message:\n                    type: string\n        '404': &id003\n          description: Not found\n"
            : "  /orders/{id}/v{$i}:\n    get:\n      parameters: *id001\n      responses:\n        '401': *id002\n        '404': *id003\n";
        $paths["/orders/{id}/v{$i}"] = ['get' => ['parameters' => $parameters, 'responses' => ['401' => $unauthorized, '404' => $missing]]];
    }

    // 39 schemas naming two blocks and, inside a flow list, two maps the first one anchored: 156 aliases.
    $refs = "openapi: 3.1.0\ninfo: {title: Orders, version: '1'}\npaths: {}\ncomponents:\n  schemas:\n";
    $schemas = [];
    foreach (range(0, 39) as $i) {
        $refs .= $i === 0
            ? "    S0:\n      type: object\n      required: &ref_0 [id, name]\n      properties: &ref_1\n        id: {type: integer}\n        name: {type: string}\n      allOf: [&ref_2 {description: Base}, &ref_3 {title: Entity}]\n"
            : "    S{$i}:\n      type: object\n      required: *ref_0\n      properties: *ref_1\n      allOf: [*ref_2, *ref_3]\n";
        $schemas["S{$i}"] = ['type' => 'object', 'required' => ['id', 'name'], 'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']], 'allOf' => [['description' => 'Base'], ['title' => 'Entity']]];
    }

    return [
        'PyYAML, a block anchored once and named at every operation' => [$pyyaml, ['openapi' => '3.1.0', 'info' => ['title' => 'Orders', 'version' => '1'], 'paths' => $paths]],
        'js-yaml, lists and maps named in block and flow' => [$refs, ['openapi' => '3.1.0', 'info' => ['title' => 'Orders', 'version' => '1'], 'paths' => new stdClass, 'components' => ['schemas' => $schemas]]],
    ];
});

it('keeps a cap on aliases wherever the text could spell a merge key, and only there', function (string $spelt, bool $capped): void {
    // 129 aliases to a one-member map, which no budget refuses, in a document small enough to keep the
    // parser's own 128: so only the cap can refuse it, and every release meets it at the same alias.
    $yaml = "openapi: 3.1.0\nx-m: &m {a: 1}\nx-copies:\n".str_repeat("  - *m\n", 129).$spelt;

    try {
        $read = json_decode((new DocumentReader)->read($yaml)->json(), true, 512, JSON_THROW_ON_ERROR);
        expect($capped)->toBeFalse()
            ->and($read['x-copies'])->toHaveCount(129);
    } catch (UnreadableDocumentException $exception) {
        // The document is valid YAML, so the refusal says why it is too dear and what to do instead, at
        // the alias past the cap.
        expect($capped)->toBeTrue()
            ->and($exception->failure)->toBe(ReadFailure::AliasExpansion)
            ->and($exception->reason)->toBe('The document names more than 128 YAML aliases to a map or a list, the most a document of its size may name where its text could spell a merge key (<<): each merge copies the whole map it names while the document is parsed. Remove its merge keys, or upload it as JSON.')
            ->and($exception->parsedLine)->toBe(132);
    }
})->with([
    'nothing' => ["x-n: 1\n", false],
    'a `<` alone' => ["x-n: 'a < b'\n", false],
    'a merge key' => ["x-n:\n  <<: *m\n", true],
    'a merge key before a tab' => ["x-n:\n  <<\t: *m\n", true],
    'a merge key in flow style' => ["x-n: {<<: *m}\n", true],
    // Releases before 7.4.21 and 8.1.9 merge a quoted key too, and an escape is a way to write `<`.
    'a quoted merge key' => ["x-n:\n  '<<': *m\n", true],
    'a double-quoted merge key' => ["x-n:\n  \"<<\": *m\n", true],
    'a merge key escaped' => ["x-n:\n  \"\\x3c\\x3c\": *m\n", true],
    'a merge key escaped by code point' => ["x-n:\n  \"\\u003c<\": *m\n", true],
    'a merge key escaped at length, in capitals' => ["x-n:\n  \"\\U0000003C\\x3C\": *m\n", true],
    'a merge key split over an escaped line break' => ["x-n: {\"<\\\n  <\": *m}\n", true],
    'a merge key ended by an escaped line break' => ["x-n: {\"<<\\\n\": *m}\n", true],
    // The text could spell one, which is all the reader asks: `<<` in prose keeps the cap as well.
    'a `<<` in prose' => ["x-n: 'shift with <<'\n", true],
    // And a `<<` no key could end at is part of a longer scalar, which no release reads as a merge.
    'a `<<` in a PDF dictionary' => ["x-n: \"<</Type /Catalog>>\\r\\n<<\\r\\n/Root 1 0 R>>\"\n", false],
    'a `<<` before an escape' => ["x-n: \"<<\\t\"\n", false],
    'a heredoc' => ["x-n: 'cat <<EOF'\n", false],
    'a `<<` inside a word' => ["x-n: a<<b\n", false],
]);

it('counts every key the installed parser reads as a merge as one the text could spell', function (): void {
    // Each spelling writes `x-n` as a map merging `x-m`, and the release decides whether it does. Wherever it
    // does, the cap must hold: lifted, it would leave the merge to copy as often as the document asks.
    $spellings = [
        "x-n:\n  <<: *m\n", "x-n:\n  <<   : *m\n", "x-n:\n  <<\t: *m\n", "x-n:\n  <<\0: *m\n", "x-n:\n  <<\x0B: *m\n",
        "x-n:\r\n  <<: *m\r\n", "x-n:\n  '<<': *m\n", "x-n:\n  \"<<\": *m\n", "x-n:\n  !!str <<: *m\n",
        "x-n:\n  \"\\x3c\\x3c\": *m\n", "x-n:\n  \"\\u003c<\": *m\n", "x-n:\n  \"\\U0000003C\\x3C\": *m\n", "x-n:\n  \"<\\x3c\": *m\n",
        "x-n: {\"<\\\n  <\": *m}\n", "x-n: {\"<\\\r\n  <\": *m}\n", "x-n: {\"<<\\\n\": *m}\n", "x-n: {\"\\\n  <<\": *m}\n",
        "x-n: {<<: *m}\n", "x-n: {<< : *m}\n", "x-n: {<<\t: *m}\n", "x-n: {<< b: *m}\n", "x-n: {'<<': *m}\n",
        "x-n: {\"<<\": *m}\n", "x-n: {\n  <<\n  : *m}\n", "x-n: [{<<: *m}]\n", "x-n: {b: 1,<<: *m}\n",
    ];

    $merging = 0;
    foreach ($spellings as $spelt) {
        try {
            $n = Yaml::parse("x-m: &m {a: 1}\n".$spelt, Yaml::PARSE_OBJECT_FOR_MAP)->{'x-n'} ?? null;
            $n = is_array($n) ? ($n[0] ?? null) : $n;
            $merges = $n instanceof stdClass && property_exists($n, 'a');
        } catch (TypeError $error) {
            // Releases before 7.4.21 and 8.1.9 get as far as the merge in a flow mapping and fail at it.
            $merges = str_starts_with($error->getMessage(), 'Unsupported operand types: array + ');
        }

        if ($merges) {
            $merging++;
            $yaml = "openapi: 3.1.0\nx-m: &m {a: 1}\nx-copies:\n".str_repeat("  - *m\n", 129).$spelt;
            expect(static fn (): mixed => (new DocumentReader)->read($yaml))->toThrow(UnreadableDocumentException::class, 'YAML aliases to a map or a list', json_encode($spelt, JSON_THROW_ON_ERROR));
        }
    }

    // Every admitted release merges at a plain key in both styles, so a probe that saw no merge proved nothing.
    expect($merging)->toBeGreaterThanOrEqual(13);
});

it('scales the cap down with the separators its text spells, admitting exactly as many aliases as it states', function (string $padding, ?int $boundary) use ($cap): void {
    // A merge key and $aliases more aliases to the one-member map it merges, after separators that build
    // nothing: commas or brackets in a quoted string, or comment lines. So only the cap can refuse it.
    $document = static fn (int $aliases): string => "openapi: 3.1.0\n{$padding}x-m: &m {a: 1}\nx-n:\n  <<: *m\nx-copies: [".implode(', ', array_fill(0, $aliases, '*m'))."]\n";

    $read = $refused = 0;
    foreach (range(max(0, ($boundary ?? 0) - 3), ($boundary ?? 0) + 3) as $aliases) {
        $yaml = $document($aliases);
        $allowed = $cap($yaml);

        try {
            (new DocumentReader)->read($yaml);
            $read++;
            expect($aliases + 1)->toBeLessThanOrEqual($allowed);
        } catch (UnreadableDocumentException $exception) {
            $refused++;
            expect($aliases + 1)->toBeGreaterThan($allowed)
                ->and($exception->failure)->toBe(ReadFailure::AliasExpansion)
                ->and($exception->reason)->toStartWith("The document names more than {$allowed} YAML aliases to a map or a list,");
        }
    }

    // Both sides of the boundary, except where the document is too large for even the merge's own alias.
    expect($refused)->toBeGreaterThan(0)
        ->and($read > 0)->toBe($boundary !== null);
})->with([
    'a small document keeps the parser\'s 128' => ['', 127],
    '3,900 commas more, still 128' => ["x-pad: '".str_repeat(',', 3_900)."'\n", 127],
    '4,000 commas, 126' => ["x-pad: '".str_repeat(',', 4_000)."'\n", 125],
    '6,000 commas, 86' => ["x-pad: '".str_repeat(',', 6_000)."'\n", 85],
    '6,000 brackets and braces, 86' => ["x-pad: '".str_repeat('[]{}', 1_500)."'\n", 85],
    '6,000 line breaks, 86' => [str_repeat("#\n", 6_000), 85],
    '6,000 carriage returns, 86' => [str_repeat("#\r", 6_000), 85],
    '100,000 commas, 5' => ["x-pad: '".str_repeat(',', 100_000)."'\n", 4],
    '600,000 commas, past 2^19: not even the merge' => ["x-pad: '".str_repeat(',', 600_000)."'\n", null],
]);

it("refuses an alias bomb past the parser's 128 at the budgets, and a merge bomb at the cap, cheaply", function (string $yaml, string $reason): void {
    memory_reset_peak_usage();
    $before = memory_get_usage();
    $started = hrtime(true);

    try {
        (new DocumentReader)->read($yaml);
        $this->fail('A document that expands far past its size was read.');
    } catch (UnreadableDocumentException $exception) {
        expect($exception->failure)->toBe(ReadFailure::AliasExpansion)
            ->and($exception->reason)->toContain($reason)
            ->and((hrtime(true) - $started) / 1e9)->toBeLessThan(1.0)
            // With the cap lifted, the parser spends 787 MB merging the 230 KB of 20,000 merges below.
            ->and(memory_get_peak_usage() - $before)->toBeLessThan(32 * 1024 * 1024);
    }
})->with(static function () use ($head, $laughs): array {
    $members = static fn (int $count): string => implode('', array_map(static fn (int $i): string => "  k{$i}: 1\n", range(1, $count)));
    $capped = 'YAML aliases to a map or a list';

    return [
        // 180 aliases over one letter: ten to the eleventh values, refused at ten thousand.
        'twenty aliases a level, nine levels deep' => [$laughs(20, 9, 'x'), 'values from'],
        'a map of 10,000 members named 10,000 times' => [$head."x-base: &b\n".$members(10_000)."x-copies:\n".str_repeat("  - *b\n", 10_000), 'values from'],
        'a map of 1,000 members merged into 20,000 maps' => [$head."x-base: &b\n".$members(1_000)."x-merged:\n".str_repeat("  - <<: *b\n", 20_000), $capped],
        'the same, under an escaped merge key' => [$head."x-base: &b\n".$members(1_000)."x-merged:\n".str_repeat("  - \"\\x3c\\x3c\": *b\n", 20_000), $capped],
        'a chain of 4,000 maps, each merging the one before' => [$head."x-chain:\n  m0: &m0 {a: 1}\n".implode('', array_map(static fn (int $i): string => "  m{$i}: &m{$i}\n    <<: *m".($i - 1)."\n    k{$i}: 1\n", range(1, 4_000))), $capped],
    ];
});

it('refuses a merge bomb at the cap its size allows, before the parser copies more than a few megabytes', function (string $yaml): void {
    // What 128 merges cost the parser, where the cap stayed at 128 for every size: 569 MB for 64 KB of
    // `[,,,,]`, 1 GB for 100 KB and 3.7 GB for 300 KB, and 341 MB for a 300 KB block map.
    memory_reset_peak_usage();
    $before = memory_get_usage();
    $started = hrtime(true);

    try {
        (new DocumentReader)->read($yaml);
        $this->fail('A merge bomb was read.');
    } catch (UnreadableDocumentException $exception) {
        expect($exception->failure)->toBe(ReadFailure::AliasExpansion)
            ->and($exception->reason)->toContain('YAML aliases to a map or a list')
            ->and((hrtime(true) - $started) / 1e9)->toBeLessThan(1.0)
            ->and(memory_get_peak_usage() - $before)->toBeLessThan(80 * 1024 * 1024);
    }
})->with(static function (): array {
    $merged = "x-merged:\n".str_repeat("- <<: *b\n", 128);
    // A list merged as a map copies one member a position, and `[,,,,]` spells a position a byte.
    $positions = static fn (int $bytes): string => "openapi: 3.1.0\nx-b: &b [".str_repeat(',', $bytes)."]\n".$merged;
    $keys = implode('', array_map(static fn (int $i): string => ' k'.base_convert((string) $i, 10, 36).":\n", range(1, 50_000)));

    return [
        '64 KB of positions' => [$positions(64_000)],
        '100 KB of positions' => [$positions(100_000)],
        '300 KB of positions' => [$positions(300_000)],
        'a block map of 300 KB' => ["openapi: 3.1.0\nx-b: &b\n".$keys.$merged],
    ];
});

it('reads descriptions shaped like the real ones that spell `<<` as the JSON they stand for', function (string $yaml, array $document, int $aliases, bool $merges): void {
    $read = (new DocumentReader)->read($yaml);
    $twin = (new DocumentReader)->read(json_encode($document, JSON_THROW_ON_ERROR));

    expect($read->json())->toBe($twin->json())
        ->and($read->contentHash())->toBe($twin->contentHash())
        ->and($read->nodes())->toEqual($twin->nodes());

    // The premise each stands for: one merges under the cap its size allows; the other names more aliases
    // than that, and reads only because no key could end at the `<<` its example holds.
    expect(Yaml::parse($yaml, Yaml::PARSE_OBJECT_FOR_MAP, maxAliasesForCollections: $aliases)->openapi)->not->toBeNull()
        ->and(static fn (): mixed => Yaml::parse($yaml, Yaml::PARSE_OBJECT_FOR_MAP, maxAliasesForCollections: $aliases - 1))->toThrow(ParseException::class, 'Maximum number of collection aliases');
    expect(str_contains($yaml, '<<: *'))->toBe($merges)
        ->and($aliases <= min(128, intdiv(524_288, 1 + strlen($yaml) - strlen(str_replace([',', '[', ']', '{', '}', "\n", "\r"], '', $yaml)))))->toBe($merges);
})->with(static function (): array {
    $prose = static fn (int $lines, string $indent): string => implode('', array_map(static fn (int $line): string => "{$indent}Step {$line} of it.\n", range(1, $lines)));
    $text = static fn (int $lines): string => implode('', array_map(static fn (int $line): string => "Step {$line} of it.\n", range(1, $lines)));

    // An operation anchored once and merged into four more methods, each overriding its id, beside a response
    // anchored the way PyYAML writes them: 4 merges and 21 aliases over 2,563 lines (58 KB). The real one is
    // 67 KB and names 25 the same way.
    $webhook = ['operationId' => 'webhookGet', 'parameters' => [['name' => 'path', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]], 'responses' => ['200' => ['description' => 'Handled']]];
    $merging = "openapi: 3.0.3\ninfo:\n  title: Host\n  version: '1'\npaths:\n";
    $paths = [];
    foreach (range(0, 21) as $i) {
        $merging .= "  /things/t{$i}:\n    get:\n      description: |\n".$prose(110, '        ')."      responses:\n        '401': ".($i === 0 ? "&id001\n          description: Unauthorized\n" : "*id001\n");
        $paths["/things/t{$i}"] = ['get' => ['description' => $text(110), 'responses' => ['401' => ['description' => 'Unauthorized']]]];
    }
    $merging .= "  /webhooks/{path}:\n    get: &webhook_op\n      operationId: webhookGet\n      parameters:\n        - name: path\n          in: path\n          required: true\n          schema: {type: string}\n      responses:\n        '200':\n          description: Handled\n";
    $paths['/webhooks/{path}'] = ['get' => $webhook];
    foreach (['post', 'put', 'delete', 'patch'] as $method) {
        $merging .= "    {$method}:\n      <<: *webhook_op\n      operationId: webhook".ucfirst($method)."\n";
        $paths['/webhooks/{path}'][$method] = array_replace($webhook, ['operationId' => 'webhook'.ucfirst($method)]);
    }

    // A shared response named at 76 operations, beside a PDF example whose dictionaries open with `<<`, over
    // 10,029 lines (230 KB). The real one is 172 KB and names 76 over 10,363: had a key been able to end at
    // its `<<`, it could name 52.
    $pdf = "%PDF-1.5\r\n1 0 obj\r\n<</Pages 2 0 R /Type/Catalog>>\r\nendobj\r\ntrailer\r\n<<\r\n/Root 1 0 R\r\n>>\r\n%%EOF";
    $mentioning = "openapi: 3.0.0\ninfo:\n  title: Scanner\n  version: '1'\npaths:\n  /scan:\n    post:\n      responses:\n        '200':\n          description: The scanned PDF\n          content:\n            application/pdf:\n              example: ".json_encode($pdf, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
    $scanned = ['/scan' => ['post' => ['responses' => ['200' => ['description' => 'The scanned PDF', 'content' => ['application/pdf' => ['example' => $pdf]]]]]]];
    foreach (range(0, 76) as $i) {
        $mentioning .= "  /devices/d{$i}:\n    get:\n      description: |\n".$prose(125, '        ')."      responses:\n        '400': ".($i === 0 ? "&ref_0\n          description: Bad request\n          content:\n            application/json:\n              schema: {type: object}\n" : "*ref_0\n");
        $scanned["/devices/d{$i}"] = ['get' => ['description' => $text(125), 'responses' => ['400' => ['description' => 'Bad request', 'content' => ['application/json' => ['schema' => ['type' => 'object']]]]]]];
    }

    return [
        'one merging an operation into four methods' => [$merging, ['openapi' => '3.0.3', 'info' => ['title' => 'Host', 'version' => '1'], 'paths' => $paths], 25, true],
        'one whose only `<<` opens a PDF dictionary' => [$mentioning, ['openapi' => '3.0.0', 'info' => ['title' => 'Scanner', 'version' => '1'], 'paths' => $scanned], 76, false],
    ];
});
