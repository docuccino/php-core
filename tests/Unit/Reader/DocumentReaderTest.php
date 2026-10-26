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
