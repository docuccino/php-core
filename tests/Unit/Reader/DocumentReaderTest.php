<?php

declare(strict_types=1);

use Docuccino\Core\Contract\Pointer;
use Docuccino\Core\Reader\DocumentReader;
use Docuccino\Core\Reader\ReadFailure;
use Docuccino\Core\Reader\Syntax;
use Docuccino\Core\Reader\UnreadableDocumentException;

/*
 * Reading bytes: JSON or YAML, kind-preserving, and refusing — with a reason a person can read — what is
 * not a document.
 */

it('reads JSON and YAML of one document as the same document', function (): void {
    $yaml = loadGolden('kitchen-sink.openapi32.yaml');
    $json = loadGolden('kitchen-sink.openapi32.json');

    $fromYaml = (new DocumentReader)->read($yaml);
    $fromJson = (new DocumentReader)->read($json);

    expect($fromYaml->inspect()->syntax)->toBe(Syntax::Yaml)
        ->and($fromJson->inspect()->syntax)->toBe(Syntax::Json)
        ->and($fromYaml->contentHash())->toBe($fromJson->contentHash())
        ->and($fromYaml->nodes())->toEqual($fromJson->nodes())
        ->and($fromYaml->validate()->isValid())->toBeTrue();
});

it('keeps an empty object and an empty list apart in both syntaxes', function (string $bytes): void {
    $document = (new DocumentReader)->read($bytes);

    // An empty `paths` map read as `[]` fails the meta-schema, so a reader that flattened it would call
    // a valid document invalid; and both syntaxes hash alike.
    expect($document->validate()->isValid())->toBeTrue()
        ->and($document->contentHash())->toBe((new DocumentReader)->read('{"openapi":"3.1.0","info":{"title":"Orders","version":"1"},"paths":{},"tags":[]}')->contentHash());
})->with([
    'json' => ['{"openapi":"3.1.0","info":{"title":"Orders","version":"1"},"paths":{},"tags":[]}'],
    'yaml' => ["openapi: 3.1.0\ninfo:\n  title: Orders\n  version: '1'\npaths: {}\ntags: []\n"],
]);

it('tells {} from [] where only the kind says which, and validation sees the difference', function (): void {
    $object = (new DocumentReader)->read("openapi: 3.1.0\ninfo: {title: Orders, version: '1'}\npaths: {}\n");
    $list = (new DocumentReader)->read("openapi: 3.1.0\ninfo: {title: Orders, version: '1'}\npaths: []\n");

    expect($object->validate()->isValid())->toBeTrue()
        ->and($list->validate()->isValid())->toBeFalse()
        ->and($list->validate()->findings[0]->pointer)->toBe('/paths');
});

it('resolves YAML anchors, aliases and merge keys', function (): void {
    $yaml = <<<'YAML'
        openapi: 3.1.0
        info: {title: Orders, version: '1'}
        paths:
          /orders:
            get:
              responses:
                '404': &missing
                  description: Not found
          /invoices:
            get:
              responses:
                '404': *missing
                '410':
                  <<: *missing
                  summary: Gone
        YAML;

    $document = (new DocumentReader)->read($yaml)->uir()->toArray();

    expect($document['paths']['/invoices']['get']['responses']['404'])->toBe(['description' => 'Not found'])
        ->and($document['paths']['/invoices']['get']['responses']['410'])->toBe(['description' => 'Not found', 'summary' => 'Gone']);
});

it('reads a merge key inside a flow mapping as the merge it is, or refuses it, and lets no parser error out', function (string $yaml, ?array $merged, array $reasons): void {
    // symfony/yaml before 7.4.21, and 8.0, throws a TypeError here rather than reading it or refusing it, so
    // which of the two happens is the release's to decide. What may never happen is the TypeError.
    try {
        $read = json_decode((new DocumentReader)->read($yaml)->json(), true, 512, JSON_THROW_ON_ERROR);
        expect($merged)->not->toBeNull()
            ->and($read['x-n'])->toBe($merged);
    } catch (UnreadableDocumentException $exception) {
        expect($exception->failure)->toBe(ReadFailure::Syntax)
            ->and($exception->reason)->toBeIn($reasons);
    }
})->with(static function (): array {
    $unsupported = 'The document is not valid YAML: A merge key (<<) inside a flow mapping is not supported; write that mapping in block style.';
    $head = "openapi: 3.1.0\nx-m: &m {a: 1}\nx-o: &o {c: 3}\n";

    return [
        'an alias to a mapping' => [$head.'x-n: {<<: *m, b: 2}', ['a' => 1, 'b' => 2], [$unsupported]],
        'a mapping written in place' => [$head.'x-n: {<<: {a: 1}, b: 2}', ['a' => 1, 'b' => 2], [$unsupported]],
        'a list of aliases' => [$head.'x-n: {<<: [*m, *o], b: 2}', ['a' => 1, 'c' => 3, 'b' => 2], [$unsupported]],
        'two merge keys' => [$head.'x-n: {<<: *m, <<: *o}', ['a' => 1, 'c' => 3], [$unsupported]],
        'inside a flow sequence' => [$head.'x-n: [{<<: *m}]', [['a' => 1]], [$unsupported]],
        // Nothing to merge, which the releases that read a merge refuse in their own words.
        'a number' => [$head.'x-n: {<<: 1}', null, [$unsupported, 'The document is not valid YAML: YAML merge keys used with a scalar value instead of an array.']],
        // Text that looked like JSON, so a YAML reading that fails is reported as the JSON it looked like.
        'a whole document in flow style' => ['{openapi: 3.1.0, x-m: &m {a: 1}, x-n: {<<: *m, b: 2}}', ['a' => 1, 'b' => 2], ['The document is not valid JSON: Syntax error.']],
    ];
});

it('refuses a mapping key starting with a NUL byte, in every style, as text it cannot read', function (string $bytes): void {
    // No PHP object can hold such a name. Releases before 7.4.21, and 8.0, threw an Error for it, from the
    // parser for a block mapping and from the copy for a flow one; later ones refuse it themselves.
    try {
        (new DocumentReader)->read($bytes);
        $this->fail('A key starting with a NUL byte was read.');
    } catch (UnreadableDocumentException $exception) {
        expect($exception->failure)->toBe(ReadFailure::Syntax)
            ->and($exception->reason)->toStartWith('The document is not valid ');
    }
})->with([
    'in a block mapping' => ["openapi: 3.1.0\n\"\\0a\": 1\n"],
    'as the whole key' => ["openapi: 3.1.0\n\"\\0\": 1\n"],
    'nested in a block mapping' => ["openapi: 3.1.0\nx:\n  \"\\0a\": 1\n"],
    'in a flow mapping' => ["openapi: 3.1.0\nx: {\"\\0a\": 1}\n"],
    'in text that looked like JSON' => ['{"openapi":"3.1.0","\u0000a":1}'],
]);

it('refuses a date the YAML parser matched and could not construct', function (string $yaml): void {
    // symfony/yaml 7.0 let the date's own exception out; every later release refuses it in these words.
    try {
        (new DocumentReader)->read($yaml);
        $this->fail('An impossible date was read.');
    } catch (UnreadableDocumentException $exception) {
        expect($exception->failure)->toBe(ReadFailure::Syntax)
            ->and($exception->reason)->toStartWith('The document is not valid YAML: ')
            ->and($exception->reason)->toEndWith('could not be parsed as it is an invalid date.');
    }
})->with([
    'a month past twelve' => ["openapi: 3.1.0\nx-released: 2024-13-45\n"],
    'an hour past twenty-four' => ["openapi: 3.1.0\nx-released: 2024-01-01 25:61:61\n"],
]);

it('refuses a YAML alias bomb cheaply rather than expanding it', function (): void {
    $yaml = "a: &a [x, x, x, x, x, x, x, x, x, x]\n";
    $previous = 'a';
    foreach (range('b', 'j') as $name) {
        $yaml .= "{$name}: &{$name} [".implode(', ', array_fill(0, 10, '*'.$previous))."]\n";
        $previous = $name;
    }

    $started = hrtime(true);

    try {
        (new DocumentReader)->read($yaml);
        $this->fail('An alias bomb was read.');
    } catch (UnreadableDocumentException $exception) {
        // Ten to the tenth values expanded; refused after ten thousand.
        expect($exception->failure)->toBe(ReadFailure::AliasExpansion)
            ->and($exception->reason)->toContain('aliases')
            ->and((hrtime(true) - $started) / 1e9)->toBeLessThan(1.0);
    }
});

it('reads a document that shares a block by alias many times over', function (): void {
    $responses = implode('', array_map(static fn (int $i): string => "        '4{$i}': *error\n", range(10, 99)));
    $yaml = "openapi: 3.1.0\ninfo: {title: Orders, version: '1'}\ncomponents:\n  x-blocks:\n    error: &error {description: Failed, content: {application/json: {schema: {type: object}}}}\npaths:\n  /orders:\n    get:\n      responses:\n{$responses}";

    expect((new DocumentReader)->read($yaml)->nodes())->toHaveCount(91);
});

it('reads a long member name over many values in time proportional to the text', function (): void {
    // An 800 KB name over 80,000 values: a pointer spelt for each value would copy the name 80,000 times,
    // 64 GB of copying from under 1 MB of JSON.
    $bytes = '{"openapi":"3.1.0","info":{"title":"Orders","version":"1"},"paths":{},"x-'.str_repeat('k', 800_000).'":['.implode(',', array_fill(0, 80_000, '1')).']}';

    $started = hrtime(true);
    $document = (new DocumentReader)->read($bytes);

    expect((hrtime(true) - $started) / 1e9)->toBeLessThan(1.0)
        ->and($document->inspect()->openapi)->toBe('3.1.0');
});

it('reads the syntax a media type names, ignoring its parameters', function (?string $mediaType, Syntax $syntax): void {
    $bytes = $syntax === Syntax::Json
        ? '{"openapi":"3.1.0","info":{"title":"Orders","version":"1"},"paths":{}}'
        : "openapi: 3.1.0\ninfo: {title: Orders, version: '1'}\npaths: {}\n";

    expect((new DocumentReader)->read($bytes, $mediaType)->inspect()->syntax)->toBe($syntax);
})->with([
    'application/json' => ['application/json', Syntax::Json],
    'with a charset' => ['application/json; charset=utf-8', Syntax::Json],
    'a +json suffix' => ['application/openapi+json', Syntax::Json],
    'application/yaml' => ['application/yaml', Syntax::Yaml],
    'application/x-yaml' => ['application/x-yaml', Syntax::Yaml],
    'text/yaml' => ['text/yaml', Syntax::Yaml],
    'text/x-yaml' => ['text/x-yaml', Syntax::Yaml],
    'a +yaml suffix' => ['application/openapi+yaml', Syntax::Yaml],
    'the OpenAPI media type' => ['application/vnd.oai.openapi', Syntax::Yaml],
    'unknown, sniffed as JSON' => ['application/octet-stream', Syntax::Json],
    'unknown, sniffed as YAML' => ['text/plain', Syntax::Yaml],
    'none, sniffed as JSON' => [null, Syntax::Json],
    'none, sniffed as YAML' => [null, Syntax::Yaml],
]);

it('reads YAML flow style that only looked like JSON, unless the media type said JSON', function (): void {
    $flow = "{openapi: 3.1.0, info: {title: Orders, version: '1'}, paths: {}}";

    expect((new DocumentReader)->read($flow)->inspect()->syntax)->toBe(Syntax::Yaml);
    expect(fn (): mixed => (new DocumentReader)->read($flow, 'application/json'))
        ->toThrow(UnreadableDocumentException::class, 'The document is not valid JSON: Syntax error.');
});

it('refuses what is not a document, saying why', function (string $bytes, ?string $mediaType, ReadFailure $failure, string $reason, ?int $line): void {
    try {
        (new DocumentReader)->read($bytes, $mediaType);
        $this->fail('The bytes were read.');
    } catch (UnreadableDocumentException $exception) {
        expect($exception->failure)->toBe($failure)
            ->and($exception->reason)->toBe($reason)
            ->and($exception->parsedLine)->toBe($line)
            ->and($exception->getMessage())->toBe($line === null ? $reason : "{$reason} (line {$line})");
    }
})->with([
    'empty' => ["  \n", null, ReadFailure::Syntax, 'The document is empty.', null],
    'not UTF-8' => ["openapi: \xC3\x28", null, ReadFailure::Encoding, 'The document is not UTF-8 text.', null],
    'broken JSON' => ['{"openapi": ', 'application/json', ReadFailure::Syntax, 'The document is not valid JSON: Syntax error.', null],
    'broken YAML' => ["openapi: 3.1.0\n  info: indented\n", null, ReadFailure::Syntax, 'The document is not valid YAML: A colon cannot be used in an unquoted mapping value.', 2],
    'a custom tag' => ["openapi: !version 3.1.0\n", null, ReadFailure::Syntax, 'The document is not valid YAML: Tags support is not enabled. Enable the "Yaml::PARSE_CUSTOM_TAGS" flag to use "!version".', 1],
    'a PHP object' => ["openapi: !php/object 'O:8:\"stdClass\":0:{}'\n", null, ReadFailure::Syntax, 'The document is not valid YAML: Object support when parsing a YAML file has been disabled.', 1],
    'a PHP constant' => ["openapi: !php/const PHP_EOL\n", null, ReadFailure::Syntax, 'The document is not valid YAML: The string "!php/const PHP_EOL" could not be parsed as a constant. Did you forget to pass the "Yaml::PARSE_CONSTANT" flag to the parser?', 1],
    'a list' => ['[1, 2]', null, ReadFailure::NotAnObject, 'The document is a list, not an object: an OpenAPI document is an object at its root.', null],
    'a string' => ['just words', null, ReadFailure::NotAnObject, 'The document is a single string, not an object: an OpenAPI document is an object at its root.', null],
    'a number' => ['42', null, ReadFailure::NotAnObject, 'The document is a single int, not an object: an OpenAPI document is an object at its root.', null],
    'null' => ['~', null, ReadFailure::NotAnObject, 'The document is null, not an object: an OpenAPI document is an object at its root.', null],
    'an infinite number' => ["openapi: 3.1.0\nx-limit: .inf\n", null, ReadFailure::Unrepresentable, 'The document holds an infinite or NaN number at /x-limit, which JSON cannot represent.', null],
    'an infinite number in a list, under a name with a slash' => ["openapi: 3.1.0\nx-a/b: [1, .inf]\n", null, ReadFailure::Unrepresentable, 'The document holds an infinite or NaN number at /x-a~1b/1, which JSON cannot represent.', null],
    'binary that is not text' => ["openapi: 3.1.0\nx-blob: !!binary /w==\n", null, ReadFailure::Unrepresentable, 'The document holds a string that is not UTF-8 text at /x-blob, which JSON cannot represent.', null],
    'nested past the writer' => [str_repeat('[', 130).str_repeat(']', 130), 'application/json', ReadFailure::TooDeep, 'The document nests more than 128 levels deep at '.str_repeat('/0', 128).', past what can be written back out.', null],
    'nested past the JSON parser' => [str_repeat('[', 600).str_repeat(']', 600), 'application/json', ReadFailure::TooDeep, 'The document nests more than 128 levels deep, past what can be written back out.', null],
]);

it('reads a byte-order mark as nothing', function (): void {
    $document = (new DocumentReader)->read("\u{FEFF}".'{"openapi":"3.1.0","info":{"title":"Orders","version":"1"},"paths":{}}');

    expect($document->inspect()->openapi)->toBe('3.1.0');
});

it('reads an unquoted YAML date as the text it was written as, never as a count of seconds', function (string $written, string $read): void {
    $document = (new DocumentReader)->read("openapi: 3.1.0\ninfo: {title: Orders, version: '1'}\npaths: {}\nx-released: {$written}\n");

    expect($document->uir()->toArray()['x-released'])->toBe($read);
})->with([
    'a date' => ['2024-01-01', '2024-01-01'],
    'a date-time in UTC' => ['2024-01-01T00:00:00Z', '2024-01-01T00:00:00Z'],
    'a date-time with an offset and a fraction' => ['2024-01-01T10:00:00.5+02:00', '2024-01-01T10:00:00.5+02:00'],
    'a date-time with no zone' => ['2024-01-01 10:30:00', '2024-01-01T10:30:00'],
]);

it('writes the document back as JSON exactly as it was written', function (): void {
    // Out of canonical order everywhere: members, parameters, a repeated tag, `{}` beside `[]`, and a map
    // whose keys read as list indexes.
    $written = '{"paths":{"/b":{"get":{"tags":["x","x"],"parameters":[{"name":"z","in":"query"},{"name":"a","in":"header"}],"responses":{"200":{"description":"OK"}}}},"/a":{}},'
        .'"info":{"version":"1","title":"Order"},"openapi":"3.1.0","security":[],"components":{"schemas":{"Empty":{}},"examples":{"0":{"value":"zero"},"1":{"value":"one"}}}}';

    $json = (new DocumentReader)->read($written)->json();

    expect(json_encode(json_decode($json)))->toBe(json_encode(json_decode($written)))
        ->and($json)->toEndWith("}\n")
        ->and((new DocumentReader)->read($json)->json())->toBe($json);
});

it('reads the JSON it writes back as the same document, for every document in the fixture trees', function (): void {
    $documents = openApiDocuments();

    foreach ($documents as $path) {
        $json = (new DocumentReader)->read((string) file_get_contents($path))->json();

        expect((new DocumentReader)->read($json)->json())->toBe($json, $path);
    }

    // A scan that found nothing would agree with itself forever, and a YAML document's JSON is the one
    // written fresh rather than copied.
    expect(count($documents))->toBeGreaterThan(90)
        ->and(count(array_filter($documents, static fn (string $path): bool => str_ends_with($path, '.yaml'))))->toBeGreaterThan(2);
});

it('writes a YAML document and its JSON twin as the same bytes', function (): void {
    expect((new DocumentReader)->read(loadGolden('kitchen-sink.openapi32.yaml'))->json())
        ->toBe((new DocumentReader)->read(loadGolden('kitchen-sink.openapi32.json'))->json());
});

it('writes JSON every node pointer resolves in', function (): void {
    $document = (new DocumentReader)->read((string) file_get_contents(golden('workbench-content.uir.json')));
    $graph = json_decode($document->json());
    expect($graph)->toBeObject();

    foreach ($document->nodes() as $node) {
        $segments = array_map(Pointer::unescape(...), array_slice(explode('/', $node->pointer), 1));

        expect(Pointer::readGraph($graph, $segments))->not->toBeNull($node->key);
    }
});

it('names the media type of each syntax, and reads that media type back as it', function (Syntax $syntax, string $mediaType): void {
    // Text either reader accepts, so only the media type can say which syntax read it.
    $bytes = '{"openapi":"3.1.0","info":{"title":"Orders","version":"1"},"paths":{}}';

    expect($syntax->mediaType())->toBe($mediaType)
        ->and((new DocumentReader)->read($bytes, $syntax->mediaType())->inspect()->syntax)->toBe($syntax);
})->with(static fn (): array => array_map(
    static fn (Syntax $syntax): array => [$syntax, ['json' => 'application/json', 'yaml' => 'application/yaml'][$syntax->value]],
    Syntax::cases(),
));
