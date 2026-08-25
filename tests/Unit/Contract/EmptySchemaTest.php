<?php

declare(strict_types=1);

use Docuccino\Core\Canonical\Canonicalizer;
use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Docuccino\Core\Contract\ContractIndex;
use Docuccino\Core\Contract\Examples\ExampleAudit;
use Docuccino\Core\Contract\Examples\ExampleReport;
use Docuccino\Core\Draft\SchemaKeywords;

/*
 * The empty-array-versus-empty-object hazard, in the one place it decides whether a build lives. A UIR
 * document is assembled as PHP arrays, where `{}` and `[]` are the same value, and JSON Schema is a
 * language in which they could not be less alike: at `additionalProperties` the first is "any member is
 * allowed" and the second is not a schema at all. The canonicalizer settles it for the artifact; these
 * hold that a subject reaching the validator from anywhere ELSE — a hand-edited artifact, an overlay,
 * a draft nothing canonicalised — is read rather than refused.
 */

/**
 * The keywords whose value is a JSON object, read off the one table that says so rather than
 * copied beside it — the canonicalizer, the structural hash and SchemaCheck all answer to it.
 *
 * @return list<string>
 */
function objectValuedKeywords(): array
{
    return SchemaKeywords::objectValued();
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
it('publishes an empty object at every object-valued schema keyword, and reads every one back', function (): void {
    $keywords = objectValuedKeywords();

    // Anti-vacuity, and the reason this reads the table instead of listing: a hand dataset here was
    // short by three keywords for as long as it existed, and every assertion below still passed.
    expect(count($keywords))->toBeGreaterThan(10)
        ->and($keywords)->toContain('additionalProperties', 'unevaluatedProperties', 'unevaluatedItems', 'additionalItems')
        // 2020-12's `contentSchema`, and the draft-07 spellings an overlay may legitimately carry.
        ->and($keywords)->toContain('contentSchema', 'definitions');

    $serializer = new CanonicalJsonSerializer;

    $published = [];
    foreach ($keywords as $keyword) {
        $canonical = (new Canonicalizer)->canonicalize([
            'components' => ['schemas' => ['Subject' => [$keyword => []]]],
        ]);

        $published[$keyword] = str_contains($serializer->serialize($canonical), '"'.$keyword.'": {}');
    }

    expect(array_keys(array_filter($published, static fn (bool $ok): bool => ! $ok)))->toBe([]);
});

it('orders every object-valued keyword rather than leaving it to sort with the data', function (): void {
    // The shape is derived, so a keyword missing from the order still publishes an object — this is
    // the half that goes stale silently, and only a scan of the list itself can see it.
    $ordered = canonicalizerSchemaOrder();

    // A source scan that stopped matching would turn this into a test of nothing, so a floor and the
    // names it must find rather than the exact count, which no legitimate addition should have to edit.
    expect(count($ordered))->toBeGreaterThan(50)
        ->and($ordered)->toContain('$ref', 'type', 'properties', 'additionalProperties', 'items');

    expect(array_values(array_diff(objectValuedKeywords(), $ordered)))->toBe([]);
});

it('still publishes an object for a positioned keyword no ordering names', function (): void {
    // What the guard above protects rather than provides. The order list is the one thing about a
    // keyword still stated by hand, so the shape does not depend on it: a keyword the table knows
    // and the ordering has not caught up with loses its place in the member run and nothing else.
    $residual = new ReflectionMethod(Canonicalizer::class, 'schemaResidual');

    expect($residual->invoke(new Canonicalizer, 'unevaluatedProperties', []))->toBeInstanceOf(stdClass::class)
        ->and($residual->invoke(new Canonicalizer, 'properties', []))->toBeInstanceOf(stdClass::class)
        // …and a member with no position at all is still data, sorted like any other unknown.
        ->and($residual->invoke(new Canonicalizer, 'propertyDependencies', []))->toBe([]);
});
