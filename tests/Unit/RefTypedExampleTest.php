<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\BuiltIn\EnumSchema;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\EnumT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Lint\ExampleSchemaLint;
use Docuccino\Core\Lint\LintRuleOptions;
use Docuccino\Core\Tests\Fixtures\DocumentedNode;
use Docuccino\Core\Tests\Fixtures\SamplePriority;
use Docuccino\Core\Tests\Fixtures\SampleStatus;
use Docuccino\Core\Tests\Support\StubTypeEngine;

/**
 * An authored example on a member whose type is carried by a `$ref` — an enum component, alone or inside
 * a nullable `anyOf`. A `$ref` states no `type` of its own, and reading the text against that absent
 * `type` published it as written: `@example "draft"` as the six characters `"draft"` against an enum of
 * `draft`, `@example null` beside a nullable enum as the four characters `null`, and `@example 2` beside
 * an integer enum as the string `"2"` — every one of them an example the document's own lint then
 * reported. An example is a value for the schema as a whole, so it is read against what the member
 * admits, through the `$ref` to the component behind it. This file pins both halves at once: what gets
 * published, and that the lint agrees.
 */
function refTypedExamples(): array
{
    $status = new EnumT(SampleStatus::class, ['Draft', 'Published']);
    $priority = new EnumT(SamplePriority::class, ['Low', 'High']);

    $components = new ComponentRegistry;
    $engine = new StubTypeEngine(classes: [
        DocumentedNode::class => new ClassMetadata(DocumentedNode::class, [
            // A quoted JSON string literal beside a bare `$ref`, and beside a nullable `anyOf` around one.
            new PropertyMetadata('state', $status, null, '"draft"'),
            new PropertyMetadata('placement', UnionT::of([$status, new NullT]), null, '"published"'),
            // Unquoted, and a member: unchanged, because the text already IS the value.
            new PropertyMetadata('plain', $status, null, 'draft'),
            // `null` beside a nullable enum: the `{type: null}` branch accepts the null, and no case is
            // the four characters `null`.
            new PropertyMetadata('cleared', UnionT::of([$status, new NullT]), null, 'null'),
            // An integer-backed enum lists numbers, so only the number is a member of it.
            new PropertyMetadata('priority', $priority, null, '2'),
            new PropertyMetadata('escalation', UnionT::of([$priority, new NullT]), null, '1'),
            // Unquoted and NOT a member: no reading of the text is a value the enum lists.
            new PropertyMetadata('wrong', $status, null, 'active'),
            new PropertyMetadata('outranked', $priority, null, '9'),
        ]),
    ]);

    (new SchemaConverter([new EnumSchema, ...DefaultTypeMappers::all()], $engine, $components))
        ->toSchema(new ClassT(DocumentedNode::class));

    return [$components->schemas(), $components];
}

it('publishes an example on a $ref-typed member as the value the enum behind it carries', function (string $property, mixed $expected): void {
    [$schemas] = refTypedExamples();
    $member = $schemas['DocumentedNode']['properties'][$property];

    // `??` would read a published null as no example at all, which is the one case here that has to be
    // told apart from absence.
    expect(array_key_exists('example', $member))->toBeTrue()
        ->and($member['example'])->toBe($expected);
})->with([
    'a bare $ref' => ['state', 'draft'],
    'a nullable anyOf around one' => ['placement', 'published'],
    'unquoted, already the value' => ['plain', 'draft'],
    'null, beside a nullable enum' => ['cleared', null],
    'a number, beside an integer enum' => ['priority', 2],
    'a number, beside a nullable integer enum' => ['escalation', 1],
]);

it('publishes nothing where no reading is a member, and names the values the enum lists', function (): void {
    // Publishing `active` would hand a consumer an example their own API rejects, which is worse than
    // none; the author is told instead, with the values they can choose from.
    [$schemas, $components] = refTypedExamples();
    $properties = $schemas['DocumentedNode']['properties'];

    $messages = array_map(static fn ($d): string => $d->message, $components->diagnostics());

    expect($properties['wrong'])->not->toHaveKey('example')
        ->and($properties['outranked'])->not->toHaveKey('example')
        ->and($messages)->toBe([
            'The example on '.DocumentedNode::class.'::$wrong ("active") does not read as "draft"/"published", so none is published.',
            'The example on '.DocumentedNode::class.'::$outranked ("9") does not read as 1/2, so none is published.',
        ]);
});

it('leaves the schema of a $ref-typed member alone while writing the example beside it', function (): void {
    // The example is written ONTO the member, so the `$ref` and the `anyOf` have to survive it: an
    // example reader that inlined the enum to read it would change what a generated client names.
    [$schemas] = refTypedExamples();
    $properties = $schemas['DocumentedNode']['properties'];

    expect($properties['state']['$ref'])->toBe('#/components/schemas/SampleStatus')
        ->and($properties['cleared']['anyOf'])->toBe([
            ['$ref' => '#/components/schemas/SampleStatus'],
            ['type' => 'null'],
        ])
        ->and($schemas['SampleStatus']['enum'])->toBe(['draft', 'published'])
        ->and($schemas['SamplePriority']['enum'])->toBe([1, 2]);
});

it('publishes nothing the example audit then rejects', function (): void {
    // The contract the reading answers to, stated by the validator rather than by the reader: every
    // example it published satisfies the schema beside it, `$ref` resolved, so the lint is silent.
    [$schemas] = refTypedExamples();

    $document = lintDocument(['GET /api/nodes' => [
        'responses' => ['200' => ['content' => ['application/json' => [
            'schema' => ['$ref' => '#/components/schemas/DocumentedNode'],
        ]]]],
    ]]);
    $document['components'] = ['schemas' => $schemas];
    $lint = new ExampleSchemaLint(new LintRuleOptions(enabled: true));

    expect(lintDiagnostics($lint, $document))->toBe([]);

    // The control, so a silent audit is not mistaken for agreement: the string `"null"` this used to
    // publish beside the nullable enum is rejected by the same audit.
    $document['components']['schemas']['DocumentedNode']['properties']['cleared']['example'] = 'null';
    $findings = lintDiagnostics($lint, $document);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->code)->toBe('lint.example-mismatch')
        ->and($findings[0]->message)->toContain('/components/schemas/DocumentedNode/properties/cleared/example');
});

it('does not let the quoted reading leak into a member that states its type', function (): void {
    // The scope of the rule, from the other side: `"7"` beside `type: integer` is still not an integer,
    // so nothing is published and the author is told — the quoted reading is what a schema stating NO
    // type falls back to, never a second chance for one that does.
    $components = new ComponentRegistry;
    $engine = new StubTypeEngine(classes: [
        DocumentedNode::class => new ClassMetadata(DocumentedNode::class, [
            new PropertyMetadata('seats', ScalarT::int(), null, '"7"'),
        ]),
    ]);

    (new SchemaConverter([new EnumSchema, ...DefaultTypeMappers::all()], $engine, $components))
        ->toSchema(new ClassT(DocumentedNode::class));

    expect($components->schemas()['DocumentedNode']['properties']['seats'])->not->toHaveKey('example')
        ->and(array_map(static fn ($d): string => $d->code, $components->diagnostics()))
        ->toContain('docblock.example-untypable');
});
