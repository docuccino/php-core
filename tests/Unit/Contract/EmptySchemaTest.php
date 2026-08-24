<?php

declare(strict_types=1);

use Docuccino\Core\Canonical\Canonicalizer;
use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Docuccino\Core\Contract\ContractIndex;
use Docuccino\Core\Contract\Examples\ExampleAudit;
use Docuccino\Core\Contract\Examples\ExampleReport;
use Docuccino\Core\Contract\SchemaCheck;

/*
 * The empty-array-versus-empty-object hazard, in the one place it decides whether a build lives. A UIR
 * document is assembled as PHP arrays, where `{}` and `[]` are the same value, and JSON Schema is a
 * language in which they could not be less alike: at `additionalProperties` the first is "any member is
 * allowed" and the second is not a schema at all. The canonicalizer settles it for the artifact; these
 * hold that a subject reaching the validator from anywhere ELSE — a hand-edited artifact, an overlay,
 * a draft nothing canonicalised — is read rather than refused.
 */

/**
 * The keywords SchemaCheck repairs, read off its own list rather than copied beside it.
 *
 * @return list<string>
 */
function objectValuedKeywords(): array
{
    /** @var list<string> $keywords */
    $keywords = (new ReflectionClass(SchemaCheck::class))->getConstant('OBJECT_KEYWORDS');

    return $keywords;
}

/** One example sitting beside a schema whose `$keyword` holds an empty array. */
function auditOfEmpty(string $keyword, mixed $example = 'anything'): ExampleReport
{
    return (new ExampleAudit(ContractIndex::fromArray([
        'paths' => [],
        'components' => ['schemas' => ['Subject' => [
            'type' => 'object',
            'properties' => ['field' => [$keyword => [], 'example' => $example]],
        ]]],
    ])))->run();
}

it('reads a schema whose object-valued keyword holds an empty array, at every such keyword', function (): void {
    $keywords = objectValuedKeywords();

    // Anti-vacuity: a list that stopped being read would make every assertion below prove nothing.
    expect($keywords)->toHaveCount(count(array_unique($keywords)))
        ->and(count($keywords))->toBeGreaterThan(10)
        ->and($keywords)->toContain('additionalProperties', 'properties', 'items');

    $refused = [];
    foreach ($keywords as $keyword) {
        $report = auditOfEmpty($keyword);

        expect($report->checked)->toBe(1);

        foreach ($report->uncheckable as $site) {
            $refused[] = $keyword.': '.$site->reason;
        }
    }

    expect($refused)->toBe([]);
});

it('treats the empty schema as the empty schema, so a free-form map accepts any member', function (): void {
    // The shape that killed an export: `array<string, mixed>` carrying an `@example`.
    $report = auditOfEmpty('additionalProperties', ['first_name' => 'Ada', 'last_name' => 'Lovelace']);

    expect($report->uncheckable)->toBe([])
        ->and($report->findings)->toBe([]);
});

/*
 * Coercing is only ever right where the keyword MEANS a schema. Below `const`, `enum`, `default`,
 * `example` and `examples` every name is instance data, and a list there is exactly what it says — so
 * repairing one would make the schema stop matching the very value it was written for.
 */
it('leaves an empty array inside instance data exactly as written', function (): void {
    $report = (new ExampleAudit(ContractIndex::fromArray([
        'paths' => [],
        'components' => ['schemas' => ['Subject' => [
            'type' => 'object',
            'properties' => ['field' => [
                'const' => ['properties' => []],
                'example' => ['properties' => []],
            ]],
        ]]],
    ])))->run();

    expect($report->uncheckable)->toBe([])
        ->and($report->findings)->toBe([]);
});

it('still refuses a keyword holding a real list, which no reading makes a schema', function (): void {
    $report = (new ExampleAudit(ContractIndex::fromArray([
        'paths' => [],
        'components' => ['schemas' => ['Subject' => [
            'type' => 'object',
            'properties' => ['field' => [
                'additionalProperties' => ['first_name', 'last_name'],
                'example' => ['first_name' => 'Ada'],
            ]],
        ]]],
    ])))->run();

    expect($report->uncheckable)->toHaveCount(1)
        ->and($report->uncheckable[0]->reason)->toBe('additionalProperties must be a json schema (object or boolean)');
});

/*
 * And the pairing that keeps the two halves honest. The canonicalizer decides which empty members the
 * ARTIFACT publishes as `{}`; SchemaCheck decides which it will read. The check may accept more than
 * the artifact can contain — that is the conservative direction — but never less, or a document we
 * published ourselves would be one the validator refuses.
 */
it('publishes an empty object at every schema keyword the canonicalizer owns, and reads every one back', function (string $keyword): void {
    $canonical = (new Canonicalizer)->canonicalize([
        'components' => ['schemas' => ['Subject' => [$keyword => []]]],
    ]);

    expect((new CanonicalJsonSerializer)->serialize($canonical))->toContain('"'.$keyword.'": {}')
        ->and(objectValuedKeywords())->toContain($keyword);
})->with([
    '$defs', 'additionalProperties', 'contains', 'dependentRequired', 'dependentSchemas', 'else', 'if',
    'items', 'not', 'patternProperties', 'properties', 'propertyNames', 'then',
]);
