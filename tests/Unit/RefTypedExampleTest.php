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
use Docuccino\Core\Tests\Fixtures\SampleStatus;
use Docuccino\Core\Tests\Support\StubTypeEngine;

/**
 * An authored example on a member whose type is carried by a `$ref` — an enum component, alone or inside
 * a nullable `anyOf`. The reading there used to be "the text stands as written", justified on the grounds
 * that nothing a string would violate is stated; a `$ref` to an enum states plenty, so `@example "draft"`
 * published the six characters `"draft"` against an enum of `draft`, which the document's own example
 * lint then reported. This file pins both halves at once: what gets published, and that the lint agrees.
 */
function refTypedExamples(): array
{
    $status = new EnumT(SampleStatus::class, ['Draft', 'Published']);

    $components = new ComponentRegistry;
    $engine = new StubTypeEngine(classes: [
        DocumentedNode::class => new ClassMetadata(DocumentedNode::class, [
            // A quoted JSON string literal beside a bare `$ref`, and beside a nullable `anyOf` around one.
            new PropertyMetadata('state', $status, null, '"draft"'),
            new PropertyMetadata('placement', UnionT::of([$status, new NullT]), null, '"published"'),
            // Unquoted, and a member: unchanged, because the text already IS the value.
            new PropertyMetadata('plain', $status, null, 'draft'),
            // Unquoted and NOT a member. The author's mistake, which nothing here can read as anything
            // else — the lint is what tells them, and it must go on doing so.
            new PropertyMetadata('wrong', $status, null, 'active'),
        ]),
    ]);

    (new SchemaConverter([new EnumSchema, ...DefaultTypeMappers::all()], $engine, $components))
        ->toSchema(new ClassT(DocumentedNode::class));

    return [$components->schemas(), $components];
}

it('publishes an example on a $ref-typed member as the value the enum behind it carries', function (string $property, mixed $expected): void {
    [$schemas] = refTypedExamples();

    expect($schemas['DocumentedNode']['properties'][$property]['example'] ?? 'nothing published')->toBe($expected);
})->with([
    'a bare $ref' => ['state', 'draft'],
    'a nullable anyOf around one' => ['placement', 'published'],
    'unquoted, already the value' => ['plain', 'draft'],
    // The control, published exactly as authored so the lint has something true to report.
    'unquoted and not a member' => ['wrong', 'active'],
]);

it('leaves the schema of a $ref-typed member alone while writing the example beside it', function (): void {
    // The example is written ONTO the member, so the `$ref` and the `anyOf` have to survive it: an
    // example reader that inlined the enum to read it would change what a generated client names.
    [$schemas] = refTypedExamples();
    $properties = $schemas['DocumentedNode']['properties'];

    expect($properties['state']['$ref'])->toBe('#/components/schemas/SampleStatus')
        ->and($properties['placement']['anyOf'])->toBe([
            ['$ref' => '#/components/schemas/SampleStatus'],
            ['type' => 'null'],
        ])
        ->and($schemas['SampleStatus']['enum'])->toBe(['draft', 'published']);
});

it('agrees with the example audit about which of them is wrong', function (): void {
    // The pairing that matters. Three of these examples now satisfy the enum behind their `$ref`, so the
    // lint is silent on them; the fourth does not, and it is reported — the lint doing its job, which a
    // change that silenced it would have broken.
    [$schemas] = refTypedExamples();

    $document = lintDocument(['GET /api/nodes' => [
        'responses' => ['200' => ['content' => ['application/json' => [
            'schema' => ['$ref' => '#/components/schemas/DocumentedNode'],
        ]]]],
    ]]);
    $document['components'] = ['schemas' => $schemas];

    $findings = lintDiagnostics(new ExampleSchemaLint(new LintRuleOptions(enabled: true)), $document);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->code)->toBe('lint.example-mismatch')
        ->and($findings[0]->message)->toContain('/components/schemas/DocumentedNode/properties/wrong/example')
        ->and($findings[0]->message)->toContain('enum');
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
