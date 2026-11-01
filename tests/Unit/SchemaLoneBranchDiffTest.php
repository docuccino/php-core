<?php

declare(strict_types=1);

use Docuccino\Core\Diff\Changeset;
use Docuccino\Core\Diff\DocumentDiffer;
use Docuccino\Core\Diff\SchemaComparator;
use Docuccino\Core\Document\UirDocument;

/*
 * A composition with one branch and nothing beside it but annotations validates exactly what that branch
 * validates: `allOf` of one schema is that schema, and so are `anyOf` and `oneOf` of one. So wrapping a
 * schema that way, which is how OpenAPI 3.0 has to write a described `$ref`, is no change to what it
 * admits, and the only thing a diff owes is the annotation the wrapper carries.
 */

/**
 * A document whose response and request both carry `$schema` as the `owner` property.
 *
 * @param  array<string, mixed>|bool  $schema
 * @return array<string, mixed>
 */
function loneBranchDocument(string $openapi, array|bool $schema): array
{
    $body = ['content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['owner' => $schema]]]]];

    return [
        'openapi' => $openapi,
        'info' => ['title' => 'Pets', 'version' => '1'],
        'paths' => ['/pets' => [
            'get' => ['responses' => ['200' => ['description' => 'OK'] + $body]],
            'put' => ['requestBody' => $body, 'responses' => ['204' => ['description' => 'Saved']]],
        ]],
        'components' => ['schemas' => [
            'Owner' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
            'Keeper' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'since' => ['type' => 'string']], 'required' => ['since']],
        ]],
    ];
}

/**
 * @param  array<string, mixed>|bool  $old
 * @param  array<string, mixed>|bool  $new
 */
function loneBranchDiff(array|bool $old, array|bool $new, string $oldVersion = '3.1.1', ?string $newVersion = null): Changeset
{
    $read = static fn (string $openapi, array|bool $schema): UirDocument => UirDocument::fromArray(loneBranchDocument($openapi, $schema));

    return (new DocumentDiffer)->diff($read($oldVersion, $old), $read($newVersion ?? $oldVersion, $new));
}

/** @return list<string> each change as its operation, verdict, code and property-relative pointer, sorted */
function loneBranchChanges(Changeset $changeset): array
{
    $changes = array_map(
        static fn ($change): string => strtok($change->path, ' ').' '.($change->breaking ? 'breaking ' : '').$change->code.' '.(string) strstr($change->path, 'owner'),
        $changeset->changes,
    );
    sort($changes);

    return $changes;
}

$owner = ['$ref' => '#/components/schemas/Owner'];

/** @return list<string> one annotation edit to `owner`, on the response and on the request */
function loneBranchDescribed(string $keyword = 'description'): array
{
    return ['GET schema.annotation-changed owner.'.$keyword, 'PUT schema.annotation-changed owner.'.$keyword];
}

it('reads a described reference wrapped in a one-branch `allOf` as the reference, reporting only the description', function (string $openapi) use ($owner): void {
    $wrapped = ['allOf' => [$owner], 'description' => 'Who owns the pet'];

    // The description is the one thing that moved: never a branch arriving, nor the type going.
    expect(loneBranchChanges(loneBranchDiff($owner, $wrapped, $openapi)))->toBe(loneBranchDescribed())
        ->and(loneBranchChanges(loneBranchDiff($wrapped, $owner, $openapi)))->toBe(loneBranchDescribed());
})->with(['3.1.1', '3.0.3']);

it('finds no change where the wrapper and the reference say the same thing', function () use ($owner): void {
    $described = ['description' => 'Who owns the pet'];

    expect(loneBranchDiff($owner + $described, ['allOf' => [$owner]] + $described)->changes)->toBe([])
        ->and(loneBranchDiff(['allOf' => [$owner]] + $described, $owner + $described)->changes)->toBe([]);
});

it('reports only the description between a newer document describing a reference and a 3.0 one that does not', function () use ($owner): void {
    // The newer side's description can only be written at 3.0 by wrapping the reference, which is how the
    // two are compared.
    $described = $owner + ['description' => 'Who owns the pet'];

    foreach (['3.1.1', '3.2.0'] as $newer) {
        expect(loneBranchChanges(loneBranchDiff($owner, $described, '3.0.3', $newer)))->toBe(loneBranchDescribed())
            ->and(loneBranchChanges(loneBranchDiff($described, $owner, $newer, '3.0.3')))->toBe(loneBranchDescribed());
    }
});

it('reads a one-branch `anyOf` or `oneOf` as its branch, since one of one is that one', function (string $keyword) use ($owner): void {
    expect(loneBranchDiff($owner, [$keyword => [$owner]])->changes)->toBe([])
        ->and(loneBranchDiff([$keyword => [$owner]], $owner)->changes)->toBe([])
        ->and(loneBranchDiff(['allOf' => [$owner]], [$keyword => [$owner]])->changes)->toBe([]);
})->with(['anyOf', 'oneOf']);

it('reads an inline branch the same way, and still reports what moved inside it', function (): void {
    $name = ['type' => 'string', 'maxLength' => 40];

    expect(loneBranchChanges(loneBranchDiff($name, ['allOf' => [$name], 'title' => 'Name'])))->toBe(loneBranchDescribed('title'));

    // A tighter bound inside the wrapper is the narrowing it would be anywhere, gating both sides.
    expect(loneBranchChanges(loneBranchDiff($name, ['allOf' => [['maxLength' => 20] + $name], 'title' => 'Name'])))->toBe([
        'GET breaking schema.refinement-narrowed owner.maxLength',
        'GET schema.annotation-changed owner.title',
        'PUT breaking schema.refinement-narrowed owner.maxLength',
        'PUT schema.annotation-changed owner.title',
    ]);
});

it('compares a wrapper round a different component as that component', function () use ($owner): void {
    // Repointing is reported as it is between two bare references, and `Keeper` requiring a member
    // `Owner` never had gates a request while being only a report on a response.
    expect(loneBranchChanges(loneBranchDiff($owner, ['allOf' => [['$ref' => '#/components/schemas/Keeper']], 'description' => 'Who keeps it'])))->toBe([
        'GET schema.annotation-changed owner.description',
        'GET schema.property-added owner.properties.since',
        'GET schema.ref-changed owner.$ref',
        'GET schema.required-added owner.required',
        'PUT breaking schema.required-added owner.required',
        'PUT schema.annotation-changed owner.description',
        'PUT schema.property-added owner.properties.since',
        'PUT schema.ref-changed owner.$ref',
    ]);
});

it('reads a reference wrapped beside an asserting sibling as the reference with that sibling', function (string $openapi) use ($owner): void {
    // `allOf: [{$ref: Owner}]` and `$ref: Owner` both apply `Owner` in place, so a `maxProperties` beside
    // either is the same intersection: one constraint arriving on `Owner`, never `Owner`'s own keywords
    // going and a branch arriving.
    $narrowed = [
        'GET breaking schema.refinement-narrowed owner.maxProperties',
        'PUT breaking schema.refinement-narrowed owner.maxProperties',
    ];

    expect(loneBranchChanges(loneBranchDiff($owner, ['allOf' => [$owner], 'maxProperties' => 1], $openapi)))->toBe($narrowed)
        ->and(loneBranchChanges(loneBranchDiff(['allOf' => [$owner], 'maxProperties' => 1], $owner, $openapi)))->toBe([
            'GET breaking schema.refinement-widened owner.maxProperties',
            'PUT schema.refinement-widened owner.maxProperties',
        ]);
})->with(['3.1.1', '3.0.3']);

it('reads the wrapped and the bare reference beside one sibling as the same schema', function () use ($owner): void {
    // At 3.1 a `$ref`'s siblings apply, so these are two spellings of `Owner` capped at one property.
    $capped = ['maxProperties' => 1];

    expect(loneBranchDiff($owner + $capped, ['allOf' => [$owner]] + $capped)->changes)->toBe([])
        ->and(loneBranchDiff(['anyOf' => [$owner]] + $capped, ['allOf' => [$owner]] + $capped)->changes)->toBe([])
        // The same edit in either spelling is the same finding.
        ->and(loneBranchChanges(loneBranchDiff($owner + $capped, ['allOf' => [$owner], 'maxProperties' => 5])))
        ->toBe(loneBranchChanges(loneBranchDiff($owner + $capped, $owner + ['maxProperties' => 5])));

    // 3.0 ignores a `$ref`'s siblings, so its export hoists them into the wrapper and the newer side's
    // narrowing is read there.
    expect(loneBranchChanges(loneBranchDiff($owner, $owner + $capped, '3.0.3', '3.1.1')))->toBe([
        'GET breaking schema.refinement-narrowed owner.maxProperties',
        'PUT breaking schema.refinement-narrowed owner.maxProperties',
    ]);
});

it('never merges an inline branch into the siblings beside it', function (): void {
    // `additionalProperties` sees only the `properties` beside it, so beside the wrapper it refuses `id`,
    // while merged up beside `properties` it would admit `id` again. The wrapper compares as written.
    $object = ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]];
    $codes = array_map(
        static fn ($change): string => $change->code,
        loneBranchDiff($object, ['allOf' => [$object], 'additionalProperties' => false])->changes,
    );

    expect($codes)->toContain('schema.all-of-branch-added')
        ->toContain('schema.property-removed');
});

it('reads a wrapper beside a sibling round another component as that reference with its sibling', function () use ($owner): void {
    // The wrapper and a bare `$ref` both apply `Keeper` in place, so this is `Owner` capped at one
    // property becoming `Keeper` capped at five, in either spelling: the move, what `Keeper` requires that
    // `Owner` never did, gating a request, and the cap widening, gating a response.
    $keeper = ['$ref' => '#/components/schemas/Keeper'];
    $repointed = [
        'GET breaking schema.refinement-widened owner.maxProperties',
        'GET schema.property-added owner.properties.since',
        'GET schema.ref-changed owner.$ref',
        'GET schema.required-added owner.required',
        'PUT breaking schema.required-added owner.required',
        'PUT schema.property-added owner.properties.since',
        'PUT schema.ref-changed owner.$ref',
        'PUT schema.refinement-widened owner.maxProperties',
    ];

    expect(loneBranchChanges(loneBranchDiff($owner + ['maxProperties' => 1], ['allOf' => [$keeper], 'maxProperties' => 5])))->toBe($repointed)
        ->and(loneBranchChanges(loneBranchDiff($owner + ['maxProperties' => 1], $keeper + ['maxProperties' => 5])))->toBe($repointed);
});

it('compares a wrapper beside a sibling as written against a side naming no component', function (): void {
    // Merged into `{$ref: Keeper, …}`, a comparison with no documents to read `Keeper` in would see
    // nothing but the sibling, and lose the one thing that says a component arrived: the branch.
    $keeper = ['$ref' => '#/components/schemas/Keeper'];
    $changes = (new SchemaComparator)->compare(['type' => 'object'], ['allOf' => [$keeper], 'type' => 'object'], 'S', 'sch:v1:0000000000000000', true);

    expect(array_map(static fn ($change): string => ($change->breaking ? 'breaking ' : '').$change->code, $changes))
        ->toBe(['breaking schema.all-of-branch-added']);
});

it('compares a lone branch repointed inside its wrapper as the bare reference repointed', function (string $keyword, string $openapi) use ($owner): void {
    // A one-branch composition is its branch, so repointing the branch repoints the schema: the move is
    // reported and the two components are compared, as between two bare references, never a branch gone
    // and another arrived. `Keeper` requiring a member `Owner` never had gates a request.
    $keeper = ['$ref' => '#/components/schemas/Keeper'];
    $repointed = [
        "GET schema.property-added owner.{$keyword}.0.properties.since",
        "GET schema.ref-changed owner.{$keyword}.0.\$ref",
        "GET schema.required-added owner.{$keyword}.0.required",
        "PUT breaking schema.required-added owner.{$keyword}.0.required",
        "PUT schema.property-added owner.{$keyword}.0.properties.since",
        "PUT schema.ref-changed owner.{$keyword}.0.\$ref",
    ];

    expect(loneBranchChanges(loneBranchDiff([$keyword => [$owner], 'description' => 'Who'], [$keyword => [$keeper], 'description' => 'Who'], $openapi)))->toBe($repointed)
        // An asserting sibling the two sides share changes nothing about the branch.
        ->and(loneBranchChanges(loneBranchDiff([$keyword => [$owner], 'maxProperties' => 1], [$keyword => [$keeper], 'maxProperties' => 1], $openapi)))->toBe($repointed);
})->with(['allOf', 'anyOf', 'oneOf'])->with(['3.1.1', '3.0.3']);

it('compares two compositions under one keyword branch by branch', function () use ($owner): void {
    // Both sides spell an `allOf`, so the branches pair and only what differs beside them is reported.
    expect(loneBranchChanges(loneBranchDiff(['allOf' => [$owner], 'description' => 'Who owns it'], ['allOf' => [$owner], 'maxProperties' => 1])))->toBe([
        'GET breaking schema.refinement-narrowed owner.maxProperties',
        'GET schema.annotation-changed owner.description',
        'PUT breaking schema.refinement-narrowed owner.maxProperties',
        'PUT schema.annotation-changed owner.description',
    ]);

    // A second branch is a constraint arriving, wrapper or not.
    expect(loneBranchChanges(loneBranchDiff(['allOf' => [$owner], 'description' => 'Who owns it'], ['allOf' => [$owner, ['minProperties' => 1]], 'description' => 'Who owns it'])))->toBe([
        'GET breaking schema.all-of-branch-added owner.allOf.1',
        'PUT breaking schema.all-of-branch-added owner.allOf.1',
    ]);
});

it('reads a one-branch composition of `false` as `false`', function (): void {
    expect(loneBranchDiff(false, ['allOf' => [false]])->changes)->toBe([])
        ->and(loneBranchDiff(['oneOf' => [false], 'description' => 'Never sent'], false)->changes)->toBe([]);
});

it('pairs a wrapped branch of a union with the component it wraps', function () use ($owner): void {
    // A union branch that wraps `Owner` IS `Owner`, so it pairs with the bare one rather than reading as
    // one branch gone and another arrived, and the inline branches left over still pair as an edit.
    $before = ['oneOf' => [$owner, ['type' => 'string']]];
    $after = ['oneOf' => [['allOf' => [$owner], 'description' => 'Who owns it'], ['type' => 'integer']]];

    expect(loneBranchChanges(loneBranchDiff($before, $after)))->toBe([
        'GET breaking schema.type-changed owner.oneOf.1.type',
        'GET schema.annotation-changed owner.oneOf.0.description',
        'PUT breaking schema.type-changed owner.oneOf.1.type',
        'PUT schema.annotation-changed owner.oneOf.0.description',
    ]);
});

it('never reads a one-slot `prefixItems` as its slot, which constrains an element and not the value', function (): void {
    $codes = array_map(
        static fn ($change): string => $change->code,
        loneBranchDiff(['type' => 'string'], ['prefixItems' => [['type' => 'string']]])->changes,
    );

    expect($codes)->toContain('schema.type-removed');
});
