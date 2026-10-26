<?php

declare(strict_types=1);

use Docuccino\Core\Contract\Pointer;
use Docuccino\Core\Reader\DocumentFinding;
use Docuccino\Core\Reader\DocumentReader;
use Docuccino\Core\Reader\FindingSource;
use Docuccino\Core\Reader\ReadFailure;
use Docuccino\Core\Reader\SourceMap;
use Docuccino\Core\Reader\SourcePosition;
use Docuccino\Core\Reader\Syntax;
use Docuccino\Core\Reader\UnreadableDocumentException;
use Docuccino\Core\Support\JsonValue;
use Symfony\Component\Yaml\Yaml;

/*
 * Where each finding and refusal sits in the text that was read: a line and a column, so a reader can be
 * pointed at the line rather than the file. A member is placed at its entry, an item at its entry, and a
 * pointer that names nothing written in the text at nowhere.
 */

it('places a member at its name and an item at its first character in JSON', function (): void {
    $json = <<<'JSON'
        {
          "openapi": "3.1.0",
          "info": {"title": "Forms", "version": "1.0"},
          "paths": {
            "/forms/{id}": {"get": {"responses": {"200": {"description": "ok"}}}},
            "a~b": {},
            "a/b": [1, {"c": 2}]
          }
        }
        JSON;

    expectPositions($json, 'application/json', [
        '/' => [1, 1],
        '/info/version' => [3, 30],
        '/paths/~1forms~1{id}/get/responses/200/description' => [5, 51],
        '/paths/a~0b' => [6, 5],
        '/paths/a~1b' => [7, 5],
        '/paths/a~1b/0' => [7, 13],
        '/paths/a~1b/1' => [7, 16],
        '/paths/a~1b/1/c' => [7, 17],
    ]);
});

it('places members and items of block YAML, past every multi-line scalar before them', function (): void {
    $yaml = <<<'YAML'
        %YAML 1.2
        ---
        # The forms API.
        openapi: 3.1.0
        info:
          title: "Forms: the API"
          version: '1.0'
          description: A plain scalar
            over two lines
          summary: |2-
              kept as written
            and more
          x-quoted: "a quoted scalar
            over two lines"
        tags:
        - name: one
          x-nested:
          - - a
            - b
        -   name: two
        paths:
          /forms/{id}:
            get:
              responses:
                '200': {description: ok}
          a~b: {}
          x~1y: {}
        'key with spaces' : x
        ...
        YAML;

    expectPositions($yaml, 'application/yaml', [
        '/' => [4, 1],
        '/info/title' => [6, 3],
        '/info/description' => [8, 3],
        '/info/summary' => [10, 3],
        '/info/x-quoted' => [13, 3],
        '/tags' => [15, 1],
        '/tags/0' => [16, 1],
        '/tags/0/name' => [16, 3],
        '/tags/0/x-nested/0' => [18, 3],
        '/tags/0/x-nested/0/1' => [19, 5],
        '/tags/1' => [20, 1],
        '/tags/1/name' => [20, 5],
        '/paths/~1forms~1{id}/get/responses/200/description' => [25, 17],
        '/paths/a~0b' => [26, 3],
        '/paths/x~01y' => [27, 3],
        '/key with spaces' => [28, 1],
    ]);
});

it('places members and items of flow YAML as symfony/yaml reads it, across lines', function (): void {
    // symfony/yaml reads `[the]` inside a plain scalar as text, an item with no comma before it as an item,
    // and a comma with nothing before it as a null item. Each index here is the index it reads.
    $yaml = <<<'YAML'
        openapi: 3.1.0
        info: {title: it's [the] API, version: '1',
          x-list: [a: b, [c] [d], , e,
            "f, g"]}
        YAML;

    $read = JsonValue::decode((new DocumentReader)->read($yaml, 'application/yaml')->json());
    expect($read['info']['title'] ?? null)->toBe("it's [the] API")
        ->and($read['info']['x-list'] ?? null)->toBe([['a' => 'b'], ['c'], ['d'], null, 'e', 'f, g']);

    expectPositions($yaml, 'application/yaml', [
        '/info/title' => [2, 8],
        '/info/version' => [2, 31],
        '/info/x-list' => [3, 3],
        '/info/x-list/0' => [3, 12],
        '/info/x-list/0/a' => [3, 12],
        '/info/x-list/1' => [3, 18],
        '/info/x-list/2' => [3, 22],
        // Nothing is written for a null item.
        '/info/x-list/3' => null,
        '/info/x-list/4' => [3, 29],
        '/info/x-list/5' => [4, 5],
    ]);
});

it('places what an alias names where the alias is written, and a merged member at the map that brings it', function (): void {
    $yaml = <<<'YAML'
        openapi: 3.1.0
        info: &info {title: Forms, version: '1'}
        x-base: &base
          a: 1
          b: {c: 2}
        x-more: &more
          <<: *base
          d: 3
        x-uses:
          <<: [*more, {f: 5}]
          a: 6
        x-block:
          <<:
            - *base
        x-copy: *info
        x-items:
          - *info
        YAML;

    expectPositions($yaml, 'application/yaml', [
        '/x-more/a' => [7, 7],
        '/x-more/b/c' => [7, 7],
        '/x-more/d' => [8, 3],
        // A member of the map's own beats a merged one.
        '/x-uses/a' => [11, 3],
        // Merged twice over: placed at the alias in this map, which is where it enters it.
        '/x-uses/b' => [10, 8],
        '/x-uses/d' => [10, 8],
        '/x-uses/f' => [10, 15],
        '/x-block/a' => [14, 7],
        '/x-copy' => [15, 1],
        '/x-copy/title' => [15, 9],
        '/x-items/0' => [17, 3],
        '/x-items/0/title' => [17, 5],
    ]);
});

it('places members of every other shape YAML writes them in', function (): void {
    $yaml = <<<'YAML'
        openapi: 3.1.0
        x-empty:
        x-after: 1
        x-entry:
          - &e
            k: v
        x-src:
          <<:
            a: 1
        x-inline:
          <<: {g: 7}
          h: 8
        x-seq: &s [1, 2]
        x-scalar: &t text
        x-flow: {k: *t, l: [*s], "tab\there": 1, 'it''s': 2, "q\"uote": 3, 200: x, 0x1A: y, m: !!str 12, n: , o: a#b, # a comment ] }
          p: 4}
        responses:
          200: {description: ok}
          0x1A: hex
        x-tagged: !!str 12
        x-last: z
        YAML;

    expectPositions($yaml, 'application/yaml', [
        '/x-empty' => [2, 1],
        '/x-after' => [3, 1],
        '/x-entry/0' => [5, 3],
        '/x-entry/0/k' => [6, 5],
        // A map written in place of a merged alias: its members are placed where it is written.
        '/x-src/a' => [9, 5],
        '/x-inline/g' => [11, 7],
        '/x-inline/h' => [12, 3],
        '/x-flow/k' => [15, 10],
        '/x-flow/l/0' => [15, 21],
        "/x-flow/tab\there" => [15, 26],
        "/x-flow/it's" => [15, 42],
        '/x-flow/q"uote' => [15, 54],
        '/x-flow/200' => [15, 68],
        // symfony/yaml reads a hexadecimal name as an integer in block style, and as written in flow style.
        '/x-flow/0x1A' => [15, 76],
        '/x-flow/m' => [15, 85],
        '/x-flow/n' => [15, 98],
        '/x-flow/o' => [15, 103],
        '/x-flow/p' => [16, 3],
        '/responses/200/description' => [18, 9],
        '/responses/26' => [19, 3],
        '/x-tagged' => [20, 1],
        '/x-last' => [21, 1],
    ]);
});

it('places one member sought alone past values nobody seeks, whatever they hold', function (): void {
    // Sought alone, everything before it is stepped over by its indentation: comments, blank lines, block
    // scalars, a quoted scalar whose next line sits as far in as its key, and flow collections holding what
    // looks like a bracket or a comment.
    $yaml = <<<'YAML'
        openapi: 3.1.0
        x-skipped:
          # a comment in a value nobody seeks
          a: |
            text: that looks like a key

          b: "a quoted scalar
          continued: at the key's own indent"
          c: {d: "}", e: it's, f: [g] # a comment ]
            , h: 'i ]'}
        tags:
        - name: one
          x-list:
          - a
        - name: two
        x-last: z
        YAML;

    expectPositions($yaml, 'application/yaml', ['/x-last' => [16, 1]]);
    expectPositions($yaml, 'application/yaml', ['/tags/1/name' => [15, 3]]);
});

it('places a member merged inside a flow mapping, where symfony/yaml reads one', function (): void {
    $yaml = "openapi: 3.1.0\nx-a: &a {a: 1}\nx-b: &b {b: 2}\nx-c: {<<: *a, c: 3}\nx-d: {<<: [*a, *b], d: 4}\n";

    try {
        (new DocumentReader)->read($yaml);
    } catch (UnreadableDocumentException $refusal) {
        // Releases before 7.4.21 and 8.1.9 cannot read one, and the reader refuses it.
        expect($refusal->failure)->toBe(ReadFailure::Syntax);

        return;
    }

    expectPositions($yaml, 'application/yaml', [
        '/x-c/a' => [4, 11],
        '/x-c/c' => [4, 15],
        '/x-d/a' => [5, 12],
        '/x-d/b' => [5, 16],
        '/x-d/d' => [5, 21],
    ]);
});

it('places the root of a document written on its start marker', function (): void {
    $yaml = "--- {openapi: 3.1.0, info: {title: T}}\n";

    try {
        (new DocumentReader)->read($yaml);
    } catch (UnreadableDocumentException $refusal) {
        // Releases before 7.4.21 read what follows the marker on its line as nothing, and so no document.
        expect($refusal->failure)->toBe(ReadFailure::NotAnObject);

        return;
    }

    expectPositions($yaml, 'application/yaml', ['/' => [1, 5], '/info/title' => [1, 29]]);
});

it('places a JSON name spelt with an escape, and an empty list', function (): void {
    expectPositions('{"caf\\u00e9": [], "b": {"c": []}}', null, ['/café' => [1, 2], '/b/c' => [1, 25]]);
});

it('reads the escaped segments of a pointer, ~1 for / and ~0 for ~', function (string $text, ?string $mediaType, array $expected): void {
    expectPositions($text, $mediaType, $expected);
})->with([
    'json' => [
        '{"paths": {"/forms/{id}": {}, "a~b": {}, "x~1y": {}, "~": {}}}',
        null,
        ['/paths/~1forms~1{id}' => [1, 12], '/paths/a~0b' => [1, 31], '/paths/x~01y' => [1, 42], '/paths/~0' => [1, 54]],
    ],
    'yaml' => [
        "paths:\n  /forms/{id}: {}\n  a~b: {}\n  x~1y: {}\n  \"~\": {}\n",
        'application/yaml',
        ['/paths/~1forms~1{id}' => [2, 3], '/paths/a~0b' => [3, 3], '/paths/x~01y' => [4, 3], '/paths/~0' => [5, 3]],
    ],
]);

it('counts lines at LF, CR or CRLF, columns in characters, and no byte order mark', function (string $text, string $pointer, array $expected): void {
    expectPositions($text, null, [$pointer => $expected]);
})->with([
    'CRLF' => ["openapi: 3.1.0\r\ninfo:\r\n  title: é\r\n  version: x\r\n", '/info/version', [4, 3]],
    'CR' => ["openapi: 3.1.0\rinfo:\r  title: é\r  version: x\r", '/info/version', [4, 3]],
    'a byte order mark' => ["\u{FEFF}{\"a\": 1}", '/a', [1, 2]],
    // Eleven bytes in, ten characters in.
    'a character of two bytes before' => ['{"é": 1, "b": 2}', '/b', [1, 10]],
]);

it('places nothing where a pointer names nothing written in the text', function (string $pointer): void {
    $yaml = "openapi: 3.1.0\ninfo:\n  title: Forms\ntags:\n- name: one\n";

    expect((new DocumentReader)->read($yaml)->position($pointer))->toBeNull();
})->with([
    'a member the document does not have' => ['/missing'],
    'a place inside a string' => ['/info/title/x'],
    'an item past the end of a list' => ['/tags/5'],
    'an index spelt with a leading zero' => ['/tags/00'],
    'no pointer at all' => ['info'],
]);

it('places a JSON member written twice where it is written last, as json_decode keeps the last', function (): void {
    expectPositions('{"a": {"b": 1}, "a": {"c": 2}}', null, ['/a' => [1, 17], '/a/c' => [1, 23], '/a/b' => null]);
});

it('places every member and item of every golden where it is written, in JSON and in YAML of every style', function (string $golden): void {
    $graph = json_decode((new DocumentReader)->read(loadGolden($golden))->json());

    $variants = [
        'pretty JSON' => [(string) json_encode($graph, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), Syntax::Json],
        'compact JSON' => [(string) json_encode($graph, JSON_UNESCAPED_SLASHES), Syntax::Json],
        'block YAML' => [Yaml::dump($graph, 100, 2, Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK), Syntax::Yaml],
        'YAML, flow from the third level' => [Yaml::dump($graph, 2, 4, Yaml::DUMP_OBJECT_AS_MAP), Syntax::Yaml],
        'flow YAML' => [Yaml::dump($graph, 0, 2, Yaml::DUMP_OBJECT_AS_MAP), Syntax::Yaml],
    ];

    foreach ($variants as $variant => [$text, $syntax]) {
        $read = json_decode((new DocumentReader)->read($text, $syntax->mediaType())->json());
        $pointers = everyPointerIn($read);
        $positions = (new SourceMap($text))->positions($syntax, $pointers);

        $misplaced = [];
        $previous = [0, 0];
        foreach ($pointers as $index => $pointer) {
            $position = $positions[$pointer];
            if ($position === null) {
                $misplaced[] = "{$variant}: {$pointer} is not placed";

                continue;
            }

            // Written in the order a walk of the document meets it, so no node sits before the one before it.
            if ([$position->line, $position->column] < $previous) {
                $misplaced[] = "{$variant}: {$pointer} is placed before the node before it";
            }

            $previous = [$position->line, $position->column];

            // A member is placed at its name, as one of the spellings that write it.
            $segments = explode('/', $pointer);
            $name = str_replace(['~1', '~0'], ['/', '~'], (string) array_pop($segments));
            if ($index > 0 && is_object(Pointer::readGraph($read, array_map(static fn (string $segment): string => str_replace(['~1', '~0'], ['/', '~'], $segment), array_slice($segments, 1))))) {
                $written = textAt($text, $position);
                $spellings = array_filter([$name, (string) json_encode($name, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), (string) json_encode($name), "'".str_replace("'", "''", $name)."'", trim(Yaml::dump($name))], static fn (string $spelling): bool => $spelling !== '');
                if (array_filter($spellings, static fn (string $spelling): bool => str_starts_with($written, $spelling)) === [] && ! ($name === '' && in_array($written[0] ?? '', ['"', "'"], true))) {
                    $misplaced[] = "{$variant}: {$pointer} is placed at ".substr($written, 0, 40);
                }
            }
        }

        // Sought a few at a time, so nearly all the text is off every sought path: the same answers.
        foreach (array_chunk(array_values(array_filter($pointers, static fn (int $index): bool => $index % 7 === 0, ARRAY_FILTER_USE_KEY)), 3) as $few) {
            foreach ((new SourceMap($text))->positions($syntax, $few) as $pointer => $position) {
                if ($position != $positions[$pointer]) {
                    $misplaced[] = "{$variant}: {$pointer} is placed elsewhere when sought alone";
                }
            }
        }

        // A scan that placed nothing would pass everything above.
        expect(count($pointers))->toBeGreaterThan(20)
            ->and(array_slice($misplaced, 0, 5))->toBe([]);
    }
})->with([
    'kitchen-sink.openapi32.json',
    'kitchen-sink.openapi32.yaml',
    'kitchen-sink.openapi30.json',
    'kitchen-sink.uir.json',
    'worked-example.openapi32.yaml',
    'tag-hierarchy.openapi32.yaml',
]);

it('places every finding validation returns, a missing member at the object it is missing from', function (): void {
    $yaml = <<<'YAML'
        openapi: 3.0.4
        info:
          title: Orders
          version: '1'
        paths:
          /orders:
            get:
              summary: List orders
        components:
          schemas:
            Order:
              type: [string, 'null']
        YAML;

    $document = (new DocumentReader)->read($yaml);
    $findings = $document->validate()->findings;

    $placed = [];
    foreach ($findings as $finding) {
        $placed[$finding->pointer.' '.$finding->keyword] = $finding->position?->toArray();
    }

    expect($findings)->not->toBeEmpty()
        ->and(array_filter($findings, static fn (DocumentFinding $finding): bool => $finding->position != $document->position($finding->pointer)))->toBe([])
        ->and($placed)->toMatchArray([
            // The finding names the operation that has no responses, and so is placed at it.
            '/paths/~1orders/get required' => ['line' => 7, 'column' => 5],
            '/components/schemas/Order/type type' => ['line' => 12, 'column' => 7],
        ]);
});

it('places an extension finding, and a version finding at the member that states the version', function (): void {
    $document = JsonValue::decode((string) file_get_contents(golden('workbench-content.uir.json')));
    expect($document)->toBeArray();

    /** @var array<string, mixed> $document */
    $document['paths']['/api/forms']['get']['x-docuccino']['owner'] = 'billing';
    $json = (string) json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    $finding = (new DocumentReader)->read($json)->validate()->findingsFrom(FindingSource::Extension)[0] ?? null;
    $version = (new DocumentReader)->read('{"info": {"title": "Orders", "version": "1"}, "openapi": "4.0.0"}')->validate()->findings[0] ?? null;

    expect($finding?->position)->not->toBeNull()
        ->and(textAt($json, $finding?->position ?? new SourcePosition(1, 1)))->toStartWith('"x-docuccino": {')
        ->and($version?->pointer)->toBe('/openapi')
        ->and($version?->position?->toArray())->toBe(['line' => 1, 'column' => 47]);
});

it('says where JSON stops being JSON', function (string $json, int $line, int $column): void {
    try {
        (new DocumentReader)->read($json, 'application/json');
        $this->fail('The text was read.');
    } catch (UnreadableDocumentException $refusal) {
        expect($refusal->failure)->toBe(ReadFailure::Syntax)
            ->and($refusal->position?->toArray())->toBe(['line' => $line, 'column' => $column])
            ->and($refusal->parsedLine)->toBe($line)
            ->and($refusal->getMessage())->toEndWith("(line {$line})");
    }
})->with([
    'a missing comma' => ["{\n  \"a\": 1\n  \"b\": 2\n}", 3, 3],
    'a trailing comma' => ['{"a": 1,}', 1, 9],
    'an unterminated string' => ['{"a": "x', 1, 9],
    'text that stops short' => ["{\"a\": [1,\n", 2, 1],
    'a raw control character' => ["{\"a\": \"x\ty\"}", 1, 9],
    'an escape JSON does not have' => ['{"a": "\q"}', 1, 8],
    'half a surrogate pair' => ['{"a": "\ud800"}', 1, 8],
    'a name no object can hold' => ['{"\u0000a": 1}', 1, 2],
    'text after the document' => ['{"a": 1} x', 1, 10],
    'a misspelt literal' => ['{"a": tru}', 1, 7],
    'a name without quotes' => ['{a: 1}', 1, 2],
]);

it('says where a refused value is, where it is one place', function (string $bytes, ReadFailure $failure, int $line, int $column): void {
    try {
        (new DocumentReader)->read($bytes);
        $this->fail('The text was read.');
    } catch (UnreadableDocumentException $refusal) {
        expect($refusal->failure)->toBe($failure)
            ->and($refusal->position?->toArray())->toBe(['line' => $line, 'column' => $column])
            ->and($refusal->parsedLine)->toBe($line);
    }
})->with([
    'a byte that is not UTF-8' => ["openapi: 3.1.0\ninfo:\n  title: caf\xC3\xA9 \xFF\n", ReadFailure::Encoding, 3, 15],
    // The list that opens the 129th level, past json_decode's own limit and past the copy's.
    'JSON nested past json_decode' => [str_repeat('[', 600).str_repeat(']', 600), ReadFailure::TooDeep, 1, 129],
    'JSON nested past the writer' => ['{"a":'.str_repeat('[', 200).str_repeat(']', 200).'}', ReadFailure::TooDeep, 1, 133],
    'an infinite number' => ["openapi: 3.1.0\ninfo:\n  x: .inf\n", ReadFailure::Unrepresentable, 3, 3],
    'NaN inside flow' => ["openapi: 3.1.0\nx: {y: [1, .nan]}\n", ReadFailure::Unrepresentable, 2, 12],
    'a float tag on what is not a number' => ["openapi: 3.1.0\ninfo:\n  x: !!float abc\n", ReadFailure::Syntax, 3, 3],
]);

it('says only the line where symfony/yaml stops, and no place for a refusal about the whole document', function (string $bytes, ReadFailure $failure, ?int $line): void {
    try {
        (new DocumentReader)->read($bytes);
        $this->fail('The text was read.');
    } catch (UnreadableDocumentException $refusal) {
        expect($refusal->failure)->toBe($failure)
            ->and($refusal->position)->toBeNull()
            ->and($refusal->parsedLine)->toBe($line);
    }
})->with([
    // A flow list left open: symfony/yaml reads to the end of the text looking for its close, and stops there.
    'YAML symfony/yaml cannot read' => ["openapi: 3.1.0\ninfo:\n  title: [a\n", ReadFailure::Syntax, 4],
    'aliases that expand the document' => [
        "a: &a [x, x, x, x, x, x, x, x, x, x]\nb: &b [*a, *a, *a, *a, *a, *a, *a, *a, *a, *a]\nc: &c [*b, *b, *b, *b, *b, *b, *b, *b, *b, *b]\nd: &d [*c, *c, *c, *c, *c, *c, *c, *c, *c, *c]\ne: [*d, *d, *d, *d, *d, *d, *d, *d, *d, *d]\n",
        ReadFailure::AliasExpansion,
        null,
    ],
    'an empty document' => ['   ', ReadFailure::Syntax, null],
]);

it('says nothing is wrong with text that is JSON throughout, nests shallowly, and is UTF-8', function (): void {
    // The halves of a surrogate pair, together, are one character.
    $map = new SourceMap('{"a": ["😀", 1.5e3, true, null]}');

    expect($map->jsonError())->toBeNull()
        ->and($map->jsonDeeperThan(128))->toBeNull()
        ->and($map->encodingError())->toBeNull();
});

it('finds the first byte that is not UTF-8 however far into a long text, and past a character cut where it is read', function (int $before): void {
    $text = str_repeat('a', $before)."caf\xC3\xA9\n\xF0\x9F\x98\x80 \xC0";

    expect((new SourceMap($text))->encodingError()?->toArray())->toBe(['line' => 2, 'column' => 3]);
})->with([
    'short' => [0],
    // The text is read 64 KiB at a time, and here a slice ends inside the two-byte é.
    'a character across a slice' => [65_536 - 4],
    'many slices in' => [300_000],
]);

it('says where JSON stops being JSON inside a name or at a lone half of a pair', function (string $json, int $column): void {
    expect((new SourceMap($json))->jsonError()?->toArray())->toBe(['line' => 1, 'column' => $column]);
})->with([
    'an escape JSON does not have, in a name' => ['{"a\q": 1}', 4],
    'a name with no colon after it' => ['{"a" 1}', 6],
    'the low half alone' => ['["\udc00"]', 3],
    'the high half before something else' => ['["\ud800A"]', 3],
]);

it('hands a position back as values a store can hold', function (): void {
    expect((new SourcePosition(12, 5))->toArray())->toBe(['line' => 12, 'column' => 5])
        // A finding built before positions existed still builds, and says it has none.
        ->and((new DocumentFinding(FindingSource::OpenApi, '/info', 'required', 'missing'))->position)->toBeNull()
        ->and((new UnreadableDocumentException(ReadFailure::Syntax, 'Bad.', 4))->position)->toBeNull()
        ->and((new UnreadableDocumentException(ReadFailure::Syntax, 'Bad.', position: new SourcePosition(7, 2)))->parsedLine)->toBe(7);
});
