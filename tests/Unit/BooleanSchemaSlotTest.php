<?php

declare(strict_types=1);

use Docuccino\Core\Canonical\Canonicalizer;
use Docuccino\Core\Diff\DocumentDiffer;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\Formats;
use Docuccino\Core\Tests\Support\EmittedReferences;
use Docuccino\Core\Tests\Support\OpenApiMetaSchema;

/**
 * A boolean at a SCHEMA SLOT — where a Schema Object hangs off something that is not one — through the
 * document model, which is the half {@see BooleanSubschemaTest} could not see. That file proved the four
 * outer slots against the canonicaliser directly, on a raw array; every emit goes through
 * `UirDocument::fromArray()` first, and there two of the four dropped the member outright.
 *
 * `schema: false` on a parameter republished with no `schema` at all — "no value is valid" turned into
 * "any value is", and the UIR spec's own `parameter.anyOf` then refused a document it had accepted. A
 * `components.schemas` member written as `false` vanished from the bucket, so every `$ref` naming it
 * dangled: a document every validator accepts and every client generator breaks on. And the differ read
 * the loss rather than the edit, reporting the tightest narrowing in the language as a non-breaking
 * `schema.type-removed`.
 *
 * The slots are held against the canonicaliser's own list, so a fifth one cannot arrive uncovered.
 */

/**
 * One document with `$value` at one schema slot, the pointer the emitted graph publishes it at, and the
 * `Canonicalizer::SCHEMA_SLOTS` entry — object and member — the case exercises. Every slot here is legal
 * in every dialect this product emits; a slot 3.2 added lives in {@see booleanSchemaSlots32()}.
 *
 * @return array<string, array{callable(mixed): array<string, mixed>, list<string|int>, string, string}>
 */
function booleanSchemaSlots(): array
{
    return [
        'components.schemas member' => [
            static fn (mixed $v): array => ['components' => ['schemas' => ['Slot' => $v]]],
            ['components', 'schemas', 'Slot'],
            'components',
            'schemas',
        ],
        'an operation parameter' => [
            static fn (mixed $v): array => ['paths' => ['/a' => ['get' => [
                'operationId' => 'a.get',
                'parameters' => [['name' => 'q', 'in' => 'query', 'schema' => $v]],
                'responses' => ['200' => ['description' => 'ok']],
            ]]]],
            ['paths', '/a', 'get', 'parameters', 0, 'schema'],
            'parameter',
            'schema',
        ],
        'components.parameters member' => [
            static fn (mixed $v): array => ['components' => ['parameters' => ['Q' => [
                'name' => 'q', 'in' => 'query', 'schema' => $v,
            ]]]],
            ['components', 'parameters', 'Q', 'schema'],
            'parameter',
            'schema',
        ],
        'a response header' => [
            static fn (mixed $v): array => ['paths' => ['/a' => ['get' => [
                'operationId' => 'a.get',
                'responses' => ['200' => ['description' => 'ok', 'headers' => ['X-T' => ['schema' => $v]]]],
            ]]]],
            ['paths', '/a', 'get', 'responses', '200', 'headers', 'X-T', 'schema'],
            'header',
            'schema',
        ],
        'components.headers member' => [
            static fn (mixed $v): array => ['components' => ['headers' => ['X-T' => ['schema' => $v]]]],
            ['components', 'headers', 'X-T', 'schema'],
            'header',
            'schema',
        ],
        'a response media type' => [
            static fn (mixed $v): array => ['paths' => ['/a' => ['get' => [
                'operationId' => 'a.get',
                'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => ['schema' => $v]]]],
            ]]]],
            ['paths', '/a', 'get', 'responses', '200', 'content', 'application/json', 'schema'],
            'mediaType',
            'schema',
        ],
        'a request body media type' => [
            static fn (mixed $v): array => ['paths' => ['/a' => ['post' => [
                'operationId' => 'a.post',
                'requestBody' => ['content' => ['application/json' => ['schema' => $v]]],
                'responses' => ['204' => ['description' => 'none']],
            ]]]],
            ['paths', '/a', 'post', 'requestBody', 'content', 'application/json', 'schema'],
            'mediaType',
            'schema',
        ],
    ];
}

/**
 * The schema slots OpenAPI 3.2 added, which the UIR and the 3.2 artifact publish as written and both
 * downlevels drop — so they are read as schemas at the two formats that HAVE the slot, and are gone at
 * the two that do not. Same table, different expectations, which is why they are declared apart.
 *
 * @return array<string, array{callable(mixed): array<string, mixed>, list<string|int>, string, string}>
 */
function booleanSchemaSlots32(): array
{
    return [
        'a response media type itemSchema' => [
            static fn (mixed $v): array => ['paths' => ['/a' => ['get' => [
                'operationId' => 'a.get',
                'responses' => ['200' => ['description' => 'ok', 'content' => ['application/jsonl' => [
                    'schema' => ['type' => 'object'], 'itemSchema' => $v,
                ]]]],
            ]]]],
            ['paths', '/a', 'get', 'responses', '200', 'content', 'application/jsonl', 'itemSchema'],
            'mediaType',
            'itemSchema',
        ],
        'a request body media type itemSchema' => [
            static fn (mixed $v): array => ['paths' => ['/a' => ['post' => [
                'operationId' => 'a.post',
                'requestBody' => ['content' => ['application/jsonl' => [
                    'schema' => ['type' => 'object'], 'itemSchema' => $v,
                ]]],
                'responses' => ['204' => ['description' => 'none']],
            ]]]],
            ['paths', '/a', 'post', 'requestBody', 'content', 'application/jsonl', 'itemSchema'],
            'mediaType',
            'itemSchema',
        ],
    ];
}

/** slot × value for the 3.2-only slots. */
function booleanSchemaSlot32Cases(): array
{
    $cases = [];

    foreach (booleanSchemaSlots32() as $slot => [$build, $pointer]) {
        foreach (['false' => false, 'true' => true, 'an empty object' => [], 'an object' => ['type' => 'string']] as $label => $value) {
            $cases[$slot.' · '.$label] = [$build, $pointer, $value];
        }
    }

    return $cases;
}

/** slot × value, the cross this file exists to walk. */
function booleanSchemaSlotCases(): array
{
    $cases = [];

    foreach (booleanSchemaSlots() as $slot => [$build, $pointer]) {
        foreach (['false' => false, 'true' => true, 'an object' => ['type' => 'string']] as $label => $value) {
            $cases[$slot.' · '.$label] = [$build, $pointer, $value];
        }
    }

    return $cases;
}

/** What one artifact publishes at `$pointer`, or the marker for a slot it dropped. */
function booleanSchemaSlotPublished(string $format, array $document, array $pointer): string
{
    $graph = json_decode(
        Formats::emit($format, UirDocument::fromArray($document), new EmitOptions)->output,
        flags: JSON_THROW_ON_ERROR,
    );

    if ($format !== 'uir') {
        // A spelling no validator accepts is half of what this file guards.
        expect(OpenApiMetaSchema::findings($format, $graph))->toBe([], $format.' meta-schema');
    }

    // The other half: a slot the model drops takes any `$ref` naming it down with it.
    expect(EmittedReferences::dangling($graph))->toBe([], $format.' references');

    $node = $graph;

    foreach ($pointer as $step) {
        $node = is_int($step)
            ? (is_array($node) ? $node[$step] ?? null : null)
            : (is_object($node) ? $node->{$step} ?? null : null);

        if ($node === null) {
            return '<<DROPPED>>';
        }
    }

    return (string) json_encode($node);
}

/** The document a slot builder produces, with the members every artifact needs around it. */
function booleanSchemaSlotDocument(callable $build, mixed $value): array
{
    return [
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'API', 'version' => '1.0.0'],
        'paths' => [],
        ...$build($value),
    ];
}

it('covers every schema slot the canonicaliser reads as one', function (): void {
    // Coupled to the table the CANONICALISER ITSELF reads: `Canonicalizer::SCHEMA_SLOTS` is what builds its
    // handler maps, so a slot cannot be read as a schema without appearing here, and one added there with
    // no case below fails this. The predecessor counted `subschemaValue(` call sites with a regex over the
    // source — a fifth slot arrived, the count still matched, and the whole suite stayed green.
    $declared = [];

    foreach (Canonicalizer::SCHEMA_SLOTS as $object => $members) {
        foreach (array_keys($members) as $member) {
            $declared[] = $object.'.'.$member;
        }
    }

    $covered = array_map(
        static fn (array $slot): string => $slot[2].'.'.$slot[3],
        [...array_values(booleanSchemaSlots()), ...array_values(booleanSchemaSlots32())],
    );

    sort($declared);
    $unique = array_values(array_unique($covered));
    sort($unique);

    // The plausible minimum, so a table that stopped being read — or a dataset that stopped matching it —
    // fails loudly instead of agreeing with itself about an empty set.
    expect(count($declared))->toBeGreaterThanOrEqual(5, 'declared outer schema slots')
        ->and($unique)->toBe($declared, 'every declared slot has a case')
        ->and(array_diff($covered, $declared))->toBe([], 'no case names a slot the canonicaliser does not read');
});

it('publishes a schema slot as written in every dialect that spells a boolean', function (callable $build, array $pointer, mixed $value): void {
    $document = booleanSchemaSlotDocument($build, $value);
    $expected = (string) json_encode($value);

    foreach (['uir', 'openapi-3.2', 'openapi-3.1'] as $format) {
        expect(booleanSchemaSlotPublished($format, $document, $pointer))->toBe($expected, $format);
    }
})->with(booleanSchemaSlotCases());

it('reads a 3.2-only schema slot as a schema where the dialect has one', function (callable $build, array $pointer, mixed $value): void {
    // `itemSchema` holds a Schema Object, so it owes the same reading as `schema` beside it: a boolean and
    // an empty object are schemas here, and the members come out in Schema Object order rather than
    // key-sorted, which is what falling through to the generic path used to publish.
    $document = booleanSchemaSlotDocument($build, $value);
    $expected = $value === [] ? '{}' : (string) json_encode($value);

    foreach (['uir', 'openapi-3.2'] as $format) {
        expect(booleanSchemaSlotPublished($format, $document, $pointer))->toBe($expected, $format);
    }
})->with(booleanSchemaSlot32Cases());

it('drops a 3.2-only schema slot where the target has no word for it', function (callable $build, array $pointer, mixed $value): void {
    // Dropped rather than published: 3.1 closes the Media Type Object with `unevaluatedProperties: false`
    // and 3.0 with `additionalProperties: false`, so an `itemSchema` that survives is a document the 3.0
    // meta-schema rejects outright. `booleanSchemaSlotPublished()` runs both oracles on the way.
    $document = booleanSchemaSlotDocument($build, $value);

    foreach (['openapi-3.1', 'openapi-3.0'] as $format) {
        expect(booleanSchemaSlotPublished($format, $document, $pointer))->toBe('<<DROPPED>>', $format);
    }
})->with(booleanSchemaSlot32Cases());

it('publishes a schema slot in the 3.0 spelling of the same constraint', function (callable $build, array $pointer, mixed $value): void {
    // Never `<<DROPPED>>`, and never the OTHER boolean: 3.0 has no word for one outside
    // `additionalProperties`, and both have an exact object spelling there.
    $expected = match ($value) {
        false => '{"not":{}}',
        true => '{}',
        default => (string) json_encode($value),
    };

    expect(booleanSchemaSlotPublished('openapi-3.0', booleanSchemaSlotDocument($build, $value), $pointer))
        ->toBe($expected);
})->with(booleanSchemaSlotCases());

it('keeps a boolean component schema the document references, so the $ref resolves', function (): void {
    // The regression with teeth. `Forbidden` used to vanish from the bucket while the parameter kept
    // pointing at it, and no emitter, meta-schema or golden said a word.
    $document = booleanSchemaSlotDocument(static fn (mixed $v): array => [
        'paths' => ['/a' => ['get' => [
            'operationId' => 'a.get',
            'parameters' => [['name' => 'q', 'in' => 'query', 'schema' => ['$ref' => '#/components/schemas/Forbidden']]],
            'responses' => ['200' => ['description' => 'ok']],
        ]]],
        'components' => ['schemas' => ['Forbidden' => $v]],
    ], false);

    foreach (['uir', 'openapi-3.2', 'openapi-3.1', 'openapi-3.0'] as $format) {
        $graph = json_decode(
            Formats::emit($format, UirDocument::fromArray($document), new EmitOptions)->output,
            flags: JSON_THROW_ON_ERROR,
        );

        expect(EmittedReferences::dangling($graph))->toBe([], $format)
            ->and($graph->components->schemas->Forbidden ?? null)
            ->toEqual($format === 'openapi-3.0' ? json_decode('{"not":{}}') : false, $format);
    }
});

/*
 * The differ half, at the two slots the model used to drop. `SchemaComparator` had already been taught
 * that a boolean is a Schema Object here, and was reading `[]` — so a parameter narrowed to "no value is
 * valid" surfaced as `schema.type-removed`, classed NON-BREAKING as that code then was on both sides, and
 * passed an `--enforce` release gate.
 * The comparator was faithful throughout: the boolean was gone before it ever saw it.
 */
it('classes a schema slot narrowed to false as breaking, on both slots and both directions', function (): void {
    $differ = new DocumentDiffer;

    $parameter = static fn (mixed $schema): UirDocument => UirDocument::fromArray(booleanSchemaSlotDocument(
        static fn (mixed $v): array => ['paths' => ['/a' => ['get' => [
            'operationId' => 'a.get',
            'parameters' => [['name' => 'q', 'in' => 'query', 'schema' => $v]],
            'responses' => ['200' => ['description' => 'ok']],
        ]]]],
        $schema,
    ));

    // Referenced from an operation on purpose: an UNREACHABLE component schema has its breaking verdict
    // downgraded, correctly — nothing uses it, so narrowing it breaks nobody.
    $component = static fn (mixed $schema): UirDocument => UirDocument::fromArray(booleanSchemaSlotDocument(
        static fn (mixed $v): array => [
            'paths' => ['/a' => ['get' => [
                'operationId' => 'a.get',
                'parameters' => [['name' => 'q', 'in' => 'query', 'schema' => ['$ref' => '#/components/schemas/Slot']]],
                'responses' => ['200' => ['description' => 'ok']],
            ]]],
            'components' => ['schemas' => ['Slot' => $v]],
        ],
        $schema,
    ));

    foreach (['a parameter' => $parameter, 'a component schema' => $component] as $label => $build) {
        $typed = $build(['type' => 'string']);
        $closed = $build(false);

        $arriving = $differ->diff($typed, $closed)->changes;
        $going = $differ->diff($closed, $typed)->changes;

        expect(array_map(static fn ($c): string => $c->code, $arriving))->toBe(['schema.always-invalid-added'], $label)
            ->and(array_map(static fn ($c): bool => $c->breaking, $arriving))->toBe([true], $label.' arriving is breaking')
            ->and(array_map(static fn ($c): string => $c->code, $going))->toBe(['schema.always-invalid-removed'], $label)
            ->and(array_map(static fn ($c): bool => $c->breaking, $going))->toBe([false], $label.' going widens');
    }
});

it('tells a boolean component schema apart from the objects that mean something else', function (): void {
    // `false`, `true` and `{}` fingerprint apart, or the pairing reads two of them as one unchanged
    // schema — `true` and `{}` ARE the same schema, and `false` is its opposite.
    $differ = new DocumentDiffer;

    $of = static fn (mixed $schema): UirDocument => UirDocument::fromArray(booleanSchemaSlotDocument(
        static fn (mixed $v): array => ['components' => ['schemas' => ['Slot' => $v]]],
        $schema,
    ));

    expect(array_map(static fn ($c): string => $c->code, $differ->diff($of(false), $of(true))->changes))
        ->toBe(['schema.always-invalid-removed'])
        ->and($differ->diff($of(true), $of([]))->changes)->toBe([]);
});

it('widens a value that is no schema at all, rather than dropping the slot', function (callable $build, array $pointer): void {
    // A degraded answer still has to be true, and at a NAMED slot it also has to still be there: the
    // empty schema says "anything", which is vague and honest, where a missing member says something else
    // and a missing component breaks every reference to it.
    foreach ([7, 'nonsense', new stdClass] as $value) {
        expect(booleanSchemaSlotPublished('uir', booleanSchemaSlotDocument($build, $value), $pointer))
            ->toBe('{}', get_debug_type($value));
    }
})->with(array_map(
    static fn (array $slot): array => [$slot[0], $slot[1]],
    booleanSchemaSlots(),
));
