<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Lint\ExampleSchemaLint;
use Docuccino\Core\Lint\LintRuleOptions;

/**
 * The build-time example audit. It runs on every build, so the two halves that matter are that it
 * catches an example contradicting the schema beside it — a string where the type says boolean is the
 * shape that shipped 85 times in one application — and that it is silent on a document whose examples
 * are all right, since a lint firing where nothing is wrong takes the useful warnings with it.
 */
$on = new LintRuleOptions(enabled: true);

/** @return array<string, mixed> */
function exampleLintDocument(array $schema, mixed $example): array
{
    return lintDocument(['GET /api/widgets' => [
        'responses' => ['200' => ['content' => ['application/json' => [
            'schema' => $schema,
            'example' => $example,
        ]]]],
    ]]);
}

it('flags an example that contradicts the type beside it', function (string $type, mixed $example, string $reason) use ($on): void {
    $findings = lintDiagnostics(new ExampleSchemaLint($on), exampleLintDocument(['type' => $type], $example));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->severity)->toBe(Severity::Warning)
        ->and($findings[0]->code)->toBe('lint.example-mismatch')
        ->and($findings[0]->message)->toContain('/paths/~1api~1widgets/get/responses/200/content/application~1json/example')
        ->and($findings[0]->message)->toContain($reason)
        ->and($findings[0]->help)->toContain('lint.examples.allow');
})->with([
    // Exactly the defect this pairs with: the string a docblock tag could only ever have held, published
    // against the type the schema computed beside it.
    'a string on a boolean' => ['boolean', 'false', 'The data (string) must match the type: boolean'],
    'a string on an integer' => ['integer', '7', 'The data (string) must match the type: integer'],
    'a string on an array' => ['array', '["a"]', 'The data (string) must match the type: array'],
    'a string on an object' => ['object', '{"a": 1}', 'The data (string) must match the type: object'],
    'a number on a string' => ['string', 7, 'The data (integer) must match the type: string'],
]);

it('flags an example that satisfies the type but breaks a constraint beside it', function () use ($on): void {
    // The audit is the whole schema, not just `type` — a coercion could never have caught this one.
    $findings = lintDiagnostics(
        new ExampleSchemaLint($on),
        exampleLintDocument(['type' => 'number', 'minimum' => 0.5], 0.1),
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('greater than or equal to 0.5');
});

it('finds an example nested inside a component schema, and names it there', function () use ($on): void {
    $document = lintDocument(['GET /api/widgets' => [
        'responses' => ['200' => ['content' => ['application/json' => [
            'schema' => ['$ref' => '#/components/schemas/Widget'],
        ]]]],
    ]]);
    $document['components'] = ['schemas' => ['Widget' => [
        'type' => 'object',
        'properties' => ['active' => ['type' => 'boolean', 'example' => 'false']],
    ]]];

    $findings = lintDiagnostics(new ExampleSchemaLint($on), $document);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('/components/schemas/Widget/properties/active/example');
});

it('says nothing about a document whose examples all satisfy their schemas', function (string $type, mixed $example) use ($on): void {
    expect(lintDiagnostics(new ExampleSchemaLint($on), exampleLintDocument(['type' => $type], $example)))->toBe([]);
})->with([
    'a boolean' => ['boolean', false],
    'an integer' => ['integer', 7],
    'a number' => ['number', 0.25],
    'a string' => ['string', 'acme'],
    'an array' => ['array', ['a']],
    'an object' => ['object', ['a' => 1]],
]);

it('says nothing about a document that carries no examples at all', function () use ($on): void {
    $document = lintDocument(['GET /api/widgets' => [
        'responses' => ['200' => ['content' => ['application/json' => ['schema' => ['type' => 'object']]]]],
    ]]);

    expect(lintDiagnostics(new ExampleSchemaLint($on), $document))->toBe([]);
});

it('is silent when it is turned off', function (): void {
    $off = new LintRuleOptions(enabled: false);

    expect(lintDiagnostics(new ExampleSchemaLint($off), exampleLintDocument(['type' => 'boolean'], 'false')))->toBe([]);
});

it('accepts a finding safelisted by its pointer', function (): void {
    $pointer = '/paths/~1api~1widgets/get/responses/200/content/application~1json/example';
    $options = new LintRuleOptions(enabled: true, allow: [$pointer]);

    expect(lintDiagnostics(new ExampleSchemaLint($options), exampleLintDocument(['type' => 'boolean'], 'false')))->toBe([])
        // A neighbouring pointer is not the same subject.
        ->and(lintDiagnostics(
            new ExampleSchemaLint(new LintRuleOptions(enabled: true, allow: [$pointer.'s'])),
            exampleLintDocument(['type' => 'boolean'], 'false'),
        ))->toHaveCount(1);
});

it('accepts a finding safelisted by the label the message names', function (): void {
    // The other name a finding goes by, so silencing one never needs a second vocabulary.
    $options = new LintRuleOptions(enabled: true, allow: ['GET /api/widgets → 200 application/json']);

    expect(lintDiagnostics(new ExampleSchemaLint($options), exampleLintDocument(['type' => 'boolean'], 'false')))->toBe([]);
});

it('keeps a message readable when one example breaks many rules at once', function () use ($on): void {
    // Three reasons and a count, rather than a report pasted into a diagnostic. Five members are wrong
    // five different ways, and each reason names the member it is about.
    $schema = ['type' => 'object', 'properties' => [
        'a' => ['type' => 'integer'],
        'b' => ['type' => 'boolean'],
        'c' => ['type' => 'array'],
        'd' => ['type' => 'object'],
        'e' => ['type' => 'number'],
    ]];
    $example = ['a' => 'x', 'b' => 'x', 'c' => 'x', 'd' => 'x', 'e' => 'x'];

    $findings = lintDiagnostics(new ExampleSchemaLint($on), exampleLintDocument($schema, $example));

    expect($findings)->toHaveCount(1)
        ->and(substr_count($findings[0]->message, ';'))->toBe(2)
        ->and($findings[0]->message)->toContain('at /a')
        ->and($findings[0]->message)->toEndWith('(and 2 more).');
});

it('says nothing that would make one build differ from another', function () use ($on): void {
    // Provenance rides along with every finding and it names files; a diagnostic carrying one would make
    // the output a function of the machine that produced it.
    $document = exampleLintDocument(['type' => 'boolean'], 'false');
    $document['paths']['/api/widgets']['get']['x-docuccino'] = [
        'provenance' => [['producer' => 'docblock', 'source' => ['file' => '/home/somebody/app/Data/WidgetData.php']]],
    ];

    $findings = lintDiagnostics(new ExampleSchemaLint($on), $document);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->not->toContain('/home/somebody')
        ->and($findings[0]->message)->not->toContain('.php');
});
