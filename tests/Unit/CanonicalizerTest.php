<?php

declare(strict_types=1);

use Docuccino\Core\Canonical\Canonicalizer;
use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Docuccino\Core\Contract\Pointer;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Draft\SchemaKeywords;
use Docuccino\Core\Emit\OpenApi30DownlevelEmitter;
use Docuccino\Core\Emit\OpenApi31DownlevelEmitter;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Extensions\Schema\EnumDecoration;
use Docuccino\Core\SpecValidation\Validator;
use Docuccino\Core\Support\JsonValue;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator as OpisValidator;

/**
 * Reverses the key order of every map (associative array) at every depth while leaving
 * list order untouched, so canonicalisation must be what makes two scrambled inputs equal.
 * Contents of `x-*` members other than `x-docuccino` are left verbatim, mirroring the
 * canonicaliser's passthrough contract (their internal order is intentionally preserved).
 */
function scrambleMaps(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }

    if (array_is_list($value)) {
        return array_map('scrambleMaps', $value);
    }

    $out = [];
    foreach (array_reverse($value, true) as $key => $inner) {
        $isVerbatimExtension = is_string($key) && str_starts_with($key, 'x-') && $key !== 'x-docuccino';
        $out[$key] = $isVerbatimExtension ? $inner : scrambleMaps($inner);
    }

    return $out;
}

beforeEach(function (): void {
    $this->emitter = new UirEmitter;
    $this->canonicalizer = new Canonicalizer;
});

it('canonicalises scrambled member order to byte-identical output', function (): void {
    $doc = workedExample();
    $scrambled = scrambleMaps($doc);

    expect($this->emitter->emitArray($scrambled))->toBe($this->emitter->emitArray($doc));
});

it('is idempotent: emitting canonical output again yields identical bytes', function (): void {
    $once = $this->emitter->emitArray(workedExample());
    $reparsed = json_decode(trim($once), true);
    $twice = $this->emitter->emitArray($reparsed);

    expect($twice)->toBe($once);
});

it('orders parameters by in-rank then name', function (): void {
    $doc = [
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'T', 'version' => '1.0.0'],
        'paths' => [
            '/a/{id}' => [
                'get' => [
                    'parameters' => [
                        ['name' => 'zeta', 'in' => 'query'],
                        ['name' => 'per_page', 'in' => 'query'],
                        ['name' => 'id', 'in' => 'path', 'required' => true],
                        ['name' => 'X-Trace', 'in' => 'header'],
                    ],
                    'responses' => ['200' => ['description' => 'ok']],
                ],
            ],
        ],
    ];

    $canonical = $this->canonicalizer->canonicalize($doc);
    $parameters = $canonical['paths']['/a/{id}']['get']['parameters'];
    $order = array_map(static fn (array $p): string => $p['name'], $parameters);

    expect($order)->toBe(['id', 'per_page', 'zeta', 'X-Trace']);
});

it('orders parameters that state neither an `in` nor a name, whichever order they arrive in', function (bool $reverse): void {
    // A `{"$ref": …}` parameter states neither, so every one of them ranks and names identically; on
    // rank and name alone the canonicaliser would leave them exactly as the list was built, and two
    // builds that assembled the same referenced parameters in different orders would emit differently.
    $refs = [
        ['$ref' => '#/components/parameters/Zeta'],
        ['$ref' => '#/components/parameters/Alpha'],
        ['name' => 'id', 'in' => 'path', 'required' => true],
    ];

    $canonical = $this->canonicalizer->canonicalize([
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'T', 'version' => '1.0.0'],
        'paths' => [
            '/a/{id}' => ['get' => [
                'parameters' => $reverse ? array_reverse($refs) : $refs,
                'responses' => ['200' => ['description' => 'ok']],
            ]],
        ],
    ]);

    expect(array_map(
        static fn (array $p): string => $p['name'] ?? $p['$ref'],
        $canonical['paths']['/a/{id}']['get']['parameters'],
    ))->toBe(['id', '#/components/parameters/Alpha', '#/components/parameters/Zeta']);
})->with([false, true]);

it('sorts map keys by code point and orders methods canonically', function (): void {
    $doc = [
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'T', 'version' => '1.0.0'],
        'paths' => [
            '/b' => ['post' => ['responses' => []], 'get' => ['responses' => []], 'delete' => ['responses' => []]],
            '/a' => ['get' => ['responses' => []]],
        ],
    ];

    $canonical = $this->canonicalizer->canonicalize($doc);

    expect(array_keys($canonical['paths']))->toBe(['/a', '/b']);
    expect(array_keys($canonical['paths']['/b']))->toBe(['get', 'post', 'delete']);
});

it('preserves declaration order while deduplicating enum values', function (): void {
    $schema = ['type' => 'string', 'enum' => ['b', 'a', 'b', 'c', 'a']];
    $doc = [
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'T', 'version' => '1.0.0'],
        'paths' => [],
        'components' => ['schemas' => ['S' => $schema]],
    ];

    $canonical = $this->canonicalizer->canonicalize($doc);

    expect($canonical['components']['schemas']['S']['enum'])->toBe(['b', 'a', 'c']);
});

it('orders map keys by unicode code point, including multibyte keys', function (): void {
    // UTF-8 byte order equals Unicode code-point order for well-formed sequences, so the
    // canonicaliser's byte-wise key sort IS the normative code-point sort (design §3). Code points:
    // A=U+0041, a=U+0061, z=U+007A, é=U+00E9, 💡=U+1F4A1.
    $doc = [
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'T', 'version' => '1.0.0'],
        'paths' => [],
        'components' => [
            'schemas' => [
                'S' => [
                    'type' => 'object',
                    'properties' => [
                        'é' => ['type' => 'string'],
                        'z' => ['type' => 'string'],
                        'A' => ['type' => 'string'],
                        '💡' => ['type' => 'string'],
                        'a' => ['type' => 'string'],
                        '日本語' => ['type' => 'string'],
                        'Z' => ['type' => 'string'],
                    ],
                ],
            ],
        ],
    ];

    $canonical = $this->canonicalizer->canonicalize($doc);
    $order = array_keys($canonical['components']['schemas']['S']['properties']);

    expect($order)->toBe(['A', 'Z', 'a', 'z', 'é', '日本語', '💡']);
});

it('orders tag members in OAS 3.2 Tag Object order and keeps declaration order of the list', function (): void {
    $doc = [
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'T', 'version' => '1.0.0'],
        'paths' => [],
        'tags' => [
            ['kind' => 'nav', 'parent' => 'Billing', 'description' => 'd', 'name' => 'Invoices', 'summary' => 's'],
            ['name' => 'Billing'],
        ],
    ];

    $canonical = $this->canonicalizer->canonicalize($doc);

    expect(array_keys($canonical['tags'][0]))->toBe(['name', 'summary', 'description', 'parent', 'kind'])
        ->and(array_column($canonical['tags'], 'name'))->toBe(['Invoices', 'Billing']);
});

it('passes unknown x-* members through verbatim but canonicalises known members', function (): void {
    $doc = [
        'openapi' => '3.2.0',
        'uir' => '1.0.0',
        'info' => ['version' => '1.0.0', 'title' => 'T'],
        'paths' => [],
        'x-vendor' => ['z' => 1, 'a' => 2],
    ];

    $canonical = $this->canonicalizer->canonicalize($doc);

    expect(array_keys($canonical))->toBe(['uir', 'openapi', 'info', 'paths', 'x-vendor']);
    expect(array_keys($canonical['info']))->toBe(['title', 'version']);
    expect($canonical['x-vendor'])->toBe(['z' => 1, 'a' => 2]);
});

it('keeps an object-valued member a JSON object even when its keys are a 0..n sequence', function (): void {
    // PHP re-coerces a numeric-string key straight back to an int, so a `properties` map keyed by a
    // tuple's indices is a PHP LIST and would serialise as `"properties": [ … ]` — not a shape any JSON
    // Schema has. Every object-valued member goes through the same sorted-map step, so this is the one
    // place that can close it whatever synthesised the keys.
    $doc = [
        'openapi' => '3.2.0',
        'uir' => '1.0.0',
        'info' => ['version' => '1.0.0', 'title' => 'T'],
        'paths' => [],
        'components' => [
            'schemas' => [
                'Tuple' => [
                    'type' => 'object',
                    'properties' => ['0' => ['type' => 'string'], '1' => ['type' => 'integer']],
                ],
            ],
        ],
    ];

    $json = (new CanonicalJsonSerializer)->serialize($this->canonicalizer->canonicalize($doc));

    expect($json)->toContain('"properties": {')
        ->and(json_decode($json, true)['components']['schemas']['Tuple']['properties'])
        ->toBe(['0' => ['type' => 'string'], '1' => ['type' => 'integer']]);
});

/**
 * An `x-*` member is somebody else's vocabulary, and only they know whether a given one is a map or a
 * list. `x-enumDescriptions` is keyed by enum value and `x-enum-descriptions` is index-parallel, so the
 * two spell the same prose as an object and as a list — and nothing here decides which: both pass
 * through in the shape they arrived in.
 */
it('passes an x-* member through in whatever shape it arrived in', function (): void {
    $doc = [
        'openapi' => '3.2.0',
        'uir' => '1.0.0',
        'info' => ['version' => '1.0.0', 'title' => 'T'],
        'paths' => [],
        'components' => [
            'schemas' => [
                'Tier' => [
                    'type' => 'integer',
                    'enum' => [0, 1],
                    'x-enumDescriptions' => (object) ['0' => 'Free.', '1' => 'Paid.'],
                    'x-enum-descriptions' => ['Free.', 'Paid.'],
                ],
            ],
        ],
    ];

    $json = (new CanonicalJsonSerializer)->serialize($this->canonicalizer->canonicalize($doc));

    expect($json)->toContain('"x-enumDescriptions": {')
        ->and($json)->toContain('"x-enum-descriptions": [')
        ->and(json_decode($json, true)['components']['schemas']['Tier']['x-enumDescriptions'])
        ->toBe(['0' => 'Free.', '1' => 'Paid.']);
});

/**
 * Why nothing here has to name that extension: a document built by the producer, and the same document
 * after the trip through JSON a warm fragment takes, emit identically — because the READER preserves the
 * shape, not the canonicaliser. Nothing below reaches for the extension by name; it starts at
 * {@see EnumDecoration}, whose map for a contiguous zero-based int-backed enum is the one shape a PHP
 * array cannot carry.
 */
it('emits a producer-built object-valued extension and its JSON round trip as the same bytes', function (): void {
    $decorated = EnumDecoration::apply(
        ['type' => 'integer', 'enum' => [0, 1, 2]],
        'x-enum-varnames',
        ['Free', 'Paid', 'Enterprise'],
        ['0' => 'Free.', '1' => 'Paid.', '2' => 'Enterprise.'],
    );

    // The cold build's own shape: the producer hands over an object, because the map's keys are a 0..n run.
    expect($decorated['x-enumDescriptions'])->toBeInstanceOf(stdClass::class);

    $cold = [
        'openapi' => '3.2.0',
        'uir' => '1.0.0',
        'info' => ['version' => '1.0.0', 'title' => 'T'],
        'paths' => [],
        'components' => ['schemas' => ['Tier' => $decorated]],
    ];

    // What a warm fragment hands back — and what a committed artifact hands a diff, and what the viewer
    // re-emits: one reader, and it keeps the object an object.
    /** @var array<string, mixed> $warm */
    $warm = JsonValue::decode((string) json_encode($cold));

    expect($warm['components']['schemas']['Tier']['x-enumDescriptions'])->toBeInstanceOf(stdClass::class)
        ->and($this->emitter->emitArray($warm))->toBe($this->emitter->emitArray($cold));
});

/**
 * The claim that let the canonicaliser stop naming `x-enumDescriptions`: every spelling of an
 * enum-value-keyed map survives the reader. The two a PHP array cannot hold come back as objects; every
 * other spelling is an assoc array that writes back as the object it was, so there is nothing to restore.
 */
it('reads every spelling of an enum-value-keyed map back as an object', function (string $spelling): void {
    $document = [
        'openapi' => '3.2.0',
        'uir' => '1.0.0',
        'info' => ['version' => '1.0.0', 'title' => 'T'],
        'paths' => [],
        'components' => ['schemas' => ['Tier' => ['type' => 'string', 'x-enumDescriptions' => JsonValue::decode($spelling)]]],
    ];

    $json = (new CanonicalJsonSerializer)->serialize($this->canonicalizer->canonicalize($document));

    expect($json)->toContain('"x-enumDescriptions": '.($spelling === '{}' ? '{}' : '{'))
        ->and($json)->not->toContain('"x-enumDescriptions": [');
})->with([
    'empty' => ['{}'],
    'zero-based run' => ['{"0":"Free.","1":"Paid."}'],
    'one-based run' => ['{"1":"Free.","2":"Paid."}'],
    'sparse indexes' => ['{"0":"Free.","2":"Paid."}'],
    'words' => ['{"draft":"Not yet.","live":"Serving."}'],
]);

/*
 * The OTHER arm of the subschema reading, and the one that says nothing.
 *
 * A boolean at a subschema position is published as written, because it is a schema. Anything that is no
 * schema at all — `items: 7`, a schema component holding a string — becomes `{}`: vague and valid beats
 * a document no validator accepts. That is a widening, and widening is the arm the rules ask to be said
 * out loud with a diagnostic. It is deliberately silent, and the three facts below are why.
 *
 * FIRST, the widening is total: it is the same answer at all three subschema positions, read off the
 * position table so the sweep cannot go short.
 */
it('widens anything that is no schema at all to the empty schema, at every subschema position', function (): void {
    $positions = [
        SchemaKeywords::POSITION_SCHEMA,
        SchemaKeywords::POSITION_SCHEMA_MAP,
        SchemaKeywords::POSITION_SCHEMA_LIST,
    ];

    $widened = [];

    foreach ($positions as $position) {
        foreach (SchemaKeywords::at($position) as $keyword) {
            foreach ([7, 'nonsense', 1.5] as $nonSchema) {
                $value = match ($position) {
                    SchemaKeywords::POSITION_SCHEMA_MAP => ['member' => $nonSchema],
                    SchemaKeywords::POSITION_SCHEMA_LIST => [$nonSchema],
                    default => $nonSchema,
                };

                $published = Pointer::read(
                    $this->canonicalizer->canonicalize(['components' => ['schemas' => ['X' => [$keyword => $value]]]]),
                    ['components', 'schemas', 'X', $keyword],
                );

                // Read the subschema back from where the POSITION says it sits, not from anywhere `{}`
                // happens to appear — a dropped keyword would leave `"X": {}` and pass a string match.
                $subschema = match ($position) {
                    // A map comes back as an array or a stdClass depending on its keys; either is a map.
                    SchemaKeywords::POSITION_SCHEMA_MAP => is_array($published) || is_object($published)
                        ? (((array) $published)['member'] ?? null)
                        : null,
                    SchemaKeywords::POSITION_SCHEMA_LIST => is_array($published) ? ($published[0] ?? null) : null,
                    default => $published,
                };

                $widened[$keyword] = ($widened[$keyword] ?? true)
                    && $subschema instanceof stdClass
                    && (array) $subschema === [];
            }
        }
    }

    // `dependentRequired` carries string lists rather than subschemas, so it is not in this sweep.
    expect(count($widened))->toBeGreaterThan(15)
        ->and(array_keys(array_filter($widened, static fn (bool $v): bool => ! $v)))->toBe([]);
});

/*
 * SECOND, the site cannot say anything useful. `subschemaValue()` is a pure function with no pointer
 * state, reached from identity hashing, content hashing, spec validation and every emitter — two of
 * those run per fragment, many times a build, with nowhere to put a diagnostic. A report that fired on
 * a 3.0 export and not on a UIR emit would be one policy per producer, which is the anti-pattern.
 *
 * `downlevel.boolean-subschema` is not the precedent it looks like: it reports a LOSS an author can act
 * on, because 3.0 has no boolean subschema and the `false` they wrote is being weakened. A value that
 * was never a schema is nobody's claim, so there is nothing to tell them about.
 */
it('says nothing when it widens, at any emitted format', function (): void {
    $document = [
        '$schema' => 'https://spec.docuccino.app/uir/1.0/schema.json',
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'T', 'version' => '1.0.0'],
        'paths' => ['/t' => ['get' => ['operationId' => 't.i', 'responses' => ['200' => ['description' => 'OK', 'content' => [
            'application/json' => ['schema' => ['type' => 'array', 'items' => 7]],
        ]]]]]],
        'components' => ['schemas' => ['Nonsense' => 'not a schema']],
    ];

    $uir = UirDocument::fromArray($document);

    expect((new UirEmitter)->emitWithReport($uir)->report->diagnostics)->toBe([])
        ->and((new OpenApi31DownlevelEmitter)->emitWithReport($uir)->report->diagnostics)->toBe([])
        ->and((new OpenApi30DownlevelEmitter)->emitWithReport($uir)->report->diagnostics)->toBe([])
        // …and the emitted bytes carry the widening at both slots, so the silence is over something real.
        ->and((new UirEmitter)->emit($uir))->toContain('"items": {}', '"Nonsense": {}');
});

/*
 * THIRD, `document.schema-invalid` cannot stand in for it, and moving the validation earlier is not the
 * fix it looks like. The validator canonicalises BEFORE validating, so the coercion runs first and
 * launders the problem — but that hop is what turns a PHP array into JSON at all, and an array cannot
 * tell the empty object from the empty list. Validate the bytes as handed over and a legitimate
 * `properties: {}` is rejected: the validator is a post-condition on what the emitter writes, not a
 * pre-condition on the array it was given, and reordering it makes it a different, wrong check.
 */
it('cannot see the widening from the spec validator, because the hop that hides it is the hop that makes JSON', function (): void {
    $document = static fn (array $subject): array => [
        '$schema' => 'https://spec.docuccino.app/uir/1.0/schema.json',
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'T', 'version' => '1.0.0'],
        'paths' => ['/t' => ['get' => ['operationId' => 't.i', 'responses' => ['200' => ['description' => 'OK']]]]],
        'components' => ['schemas' => ['X' => $subject]],
    ];

    $withoutTheHop = static function (array $doc): array {
        $schema = json_decode((string) file_get_contents(Validator::defaultSchemaPath()), false, flags: JSON_THROW_ON_ERROR);
        $error = (new OpisValidator)->validate(json_decode((string) json_encode($doc), false, flags: JSON_THROW_ON_ERROR), $schema)->error();

        return $error === null ? [] : (new ErrorFormatter)->format($error, true);
    };

    $nonSchema = $document(['type' => 'array', 'items' => 7]);
    $legitimate = $document(['type' => 'object', 'properties' => []]);

    // The laundering: the product's validator sees nothing wrong with a slot holding an integer.
    expect((new Validator)->validate($nonSchema)->errors)->toBe([])
        ->and($withoutTheHop($nonSchema))->toHaveKey('/components/schemas/X/items')
        // And the reason it cannot simply validate first: the same check refuses an empty object, which
        // every schema the product builds from an `array<string, mixed>` arrives as.
        ->and((new Validator)->validate($legitimate)->errors)->toBe([])
        ->and($withoutTheHop($legitimate))->toHaveKey('/components/schemas/X/properties');
});
