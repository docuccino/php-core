<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Lint\LintRuleOptions;
use Docuccino\Core\Lint\VacuousUnionLint;

/**
 * The vacuous-union lint: an `anyOf` carrying an empty `{}` branch beside typed ones validates as
 * anything, so the finding names the operation and the pointer; a fully-typed union, a nullable
 * branch, and a provenance-only decoration each stay silent or count as the shape they are. The walk
 * reads schemas only, so a data value shaped like a union — an example, a default, an enum member —
 * is not one.
 */
$on = new LintRuleOptions(enabled: true);

it('flags an anyOf whose empty branch erases the typed contract, with its pointer', function () use ($on): void {
    $document = lintDocument(['GET /api/positions' => [
        'responses' => ['200' => ['content' => ['application/json' => ['schema' => [
            'anyOf' => [[], ['$ref' => '#/components/schemas/PositionDescriptionData']],
        ]]]]],
    ]]);

    $findings = lintDiagnostics(new VacuousUnionLint($on), $document);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->severity)->toBe(Severity::Info)
        ->and($findings[0]->code)->toBe('lint.vacuous-union')
        ->and($findings[0]->message)->toContain('GET /api/positions')
        ->and($findings[0]->message)->toContain('/responses/200/content/application/json/schema/anyOf')
        ->and($findings[0]->help)->toContain('lint.vacuous_union.allow');
});

it('treats a branch carrying only x- extension members as empty', function () use ($on): void {
    $document = lintDocument(['GET /api/positions' => [
        'responses' => ['200' => ['content' => ['application/json' => ['schema' => [
            'anyOf' => [['x-docuccino' => ['provenance' => []]], ['type' => 'object']],
        ]]]]],
    ]]);

    expect(lintDiagnostics(new VacuousUnionLint($on), $document))->toHaveCount(1);
});

it('stays silent where the union constrains', function (array $schema) use ($on): void {
    $document = lintDocument(['GET /api/a' => [
        'responses' => ['200' => ['content' => ['application/json' => ['schema' => $schema]]]],
    ]]);

    expect(lintDiagnostics(new VacuousUnionLint($on), $document))->toBe([]);
})->with([
    'all branches typed' => [['anyOf' => [['type' => 'string'], ['type' => 'integer']]]],
    'a nullable branch is a constraint' => [['anyOf' => [['$ref' => '#/components/schemas/A'], ['type' => 'null']]]],
    'a single-branch anyOf claims nothing extra' => [['anyOf' => [[]]]],
    'no unions at all' => [['type' => 'object']],
]);

it('finds a vacuous union nested inside properties and items', function () use ($on): void {
    $document = lintDocument(['GET /api/a' => [
        'responses' => ['200' => ['content' => ['application/json' => ['schema' => [
            'type' => 'object',
            'properties' => ['rows' => ['type' => 'array', 'items' => [
                'anyOf' => [[], ['type' => 'string']],
            ]]],
        ]]]]],
    ]]);

    $findings = lintDiagnostics(new VacuousUnionLint($on), $document);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('/properties/rows/items/anyOf');
});

it('says so differently when every branch is empty', function () use ($on): void {
    $document = lintDocument(['GET /api/positions' => [
        'responses' => ['200' => ['content' => ['application/json' => ['schema' => [
            'anyOf' => [[], ['x-docuccino' => ['provenance' => []]]],
        ]]]]],
    ]]);

    $findings = lintDiagnostics(new VacuousUnionLint($on), $document);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('whose every branch is an unconstrained {}')
        // The typed-branch half of the wording would be a lie here.
        ->and($findings[0]->message)->not->toContain('typed branches');
});

it('stays out of subtrees that carry data rather than schemas', function (array $schema) use ($on): void {
    // A value a consumer sees — an example, a default, an enum member, an extension payload — may be
    // any JSON at all, including something shaped exactly like a vacuous union.
    $document = lintDocument(['GET /api/a' => [
        'responses' => ['200' => ['content' => ['application/json' => ['schema' => $schema]]]],
    ]]);

    // The control, so a walk that stopped reaching schemas at all can't pass this row: the same shape
    // at a schema position is exactly what the lint fires on.
    $atSchema = lintDocument(['GET /api/a' => [
        'responses' => ['200' => ['content' => ['application/json' => ['schema' => [
            'anyOf' => [[], ['type' => 'string']],
        ]]]]],
    ]]);

    expect(lintDiagnostics(new VacuousUnionLint($on), $document))->toBe([])
        ->and(lintDiagnostics(new VacuousUnionLint($on), $atSchema))->toHaveCount(1);
})->with([
    'an example value shaped like a union' => [['type' => 'object', 'example' => ['anyOf' => [[], ['type' => 'string']]]]],
    'a named example under examples' => [['type' => 'object', 'examples' => [['anyOf' => [[], ['type' => 'string']]]]]],
    'a default value' => [['type' => 'object', 'default' => ['anyOf' => [[], ['type' => 'string']]]]],
    'an enum member' => [['enum' => [['anyOf' => [[], ['type' => 'string']]]]]],
    'a const literal' => [['const' => ['anyOf' => [[], ['type' => 'string']]]]],
    'an x- extension subtree' => [['type' => 'object', 'x-vendor' => ['anyOf' => [[], ['type' => 'string']]]]],
]);

it('honours the safelist and the off-switch', function (LintRuleOptions $options) use ($on): void {
    $document = lintDocument(['GET /api/positions' => [
        'responses' => ['200' => ['content' => ['application/json' => ['schema' => [
            'anyOf' => [[], ['type' => 'object']],
        ]]]]],
    ]]);

    expect(lintDiagnostics(new VacuousUnionLint($on), $document))->toHaveCount(1)
        ->and(lintDiagnostics(new VacuousUnionLint($options), $document))->toBe([]);
})->with([
    'disabled' => [new LintRuleOptions(enabled: false)],
    'safelisted by signature' => [new LintRuleOptions(enabled: true, allow: ['GET /api/positions'])],
]);
