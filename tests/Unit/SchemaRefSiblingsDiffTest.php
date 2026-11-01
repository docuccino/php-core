<?php

declare(strict_types=1);

use Docuccino\Core\Diff\Changeset;
use Docuccino\Core\Diff\DocumentDiffer;
use Docuccino\Core\Document\UirDocument;

/*
 * Under JSON Schema 2020-12 a keyword beside a `$ref` still applies, so `{$ref: X, …S}` is the
 * intersection of X and S, both applied in place. An intersection holds wherever both of its parts do,
 * so against a side that does not spell the same pointer it is compared part by part: the pointer read
 * through as a bare one is, then S against the other side's siblings. Comparing it as written instead
 * looks no further than the name, and misses whatever the component it now names says.
 */

/**
 * A document whose response and request both carry `$schema` as the `owner` property, beside two
 * components: `Owner`, and `Keeper`, which requires a `since` that `Owner` never had.
 *
 * @param  array<string, mixed>  $schema
 * @param  array<string, mixed>  $components
 * @return array<string, mixed>
 */
function refSiblingsDocument(string $openapi, array $schema, array $components = []): array
{
    $body = ['content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['owner' => $schema]]]]];

    return [
        'openapi' => $openapi,
        'info' => ['title' => 'Pets', 'version' => '1'],
        'paths' => ['/pets' => [
            'get' => ['responses' => ['200' => ['description' => 'OK'] + $body]],
            'put' => ['requestBody' => $body, 'responses' => ['204' => ['description' => 'Saved']]],
        ]],
        'components' => ['schemas' => $components + [
            'Owner' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
            'Keeper' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'since' => ['type' => 'string']], 'required' => ['since']],
        ]],
    ];
}

/**
 * @param  array<string, mixed>  $old
 * @param  array<string, mixed>  $new
 * @param  array<string, mixed>  $components
 */
function refSiblingsDiff(array $old, array $new, string $oldVersion = '3.1.1', ?string $newVersion = null, array $components = []): Changeset
{
    return (new DocumentDiffer)->diff(
        UirDocument::fromArray(refSiblingsDocument($oldVersion, $old, $components)),
        UirDocument::fromArray(refSiblingsDocument($newVersion ?? $oldVersion, $new, $components)),
    );
}

/** @return list<string> each change as its operation, verdict, code and property-relative pointer, sorted */
function refSiblingsChanges(Changeset $changeset): array
{
    $changes = array_map(
        static fn ($change): string => strtok($change->path, ' ').' '.($change->breaking ? 'breaking ' : '').$change->code.' '.(string) strstr($change->path, 'owner'),
        $changeset->changes,
    );
    sort($changes);

    return $changes;
}

$owner = ['$ref' => '#/components/schemas/Owner'];
$keeper = ['$ref' => '#/components/schemas/Keeper'];

it('reads the component a pointer with a sibling now names, so a request owes what it requires', function () use ($owner, $keeper): void {
    // `Keeper` requires `since`, so a request that was valid against `Owner` may no longer be: that gates
    // a request, beside the move and the cap widening. Compared by name alone, it read as safe.
    expect(refSiblingsChanges(refSiblingsDiff($owner + ['maxProperties' => 1], $keeper + ['maxProperties' => 5])))->toBe([
        'GET breaking schema.refinement-widened owner.maxProperties',
        'GET schema.property-added owner.properties.since',
        'GET schema.ref-changed owner.$ref',
        'GET schema.required-added owner.required',
        'PUT breaking schema.required-added owner.required',
        'PUT schema.property-added owner.properties.since',
        'PUT schema.ref-changed owner.$ref',
        'PUT schema.refinement-widened owner.maxProperties',
    ]);
});

it('reads the component a pointer with a sibling no longer names, so a response loses what it promised', function () use ($owner, $keeper): void {
    // The sibling is the same on both sides, so the move is the whole change: `Owner` promises no
    // `since`, which a reader of `Keeper` was reading. It gates a response.
    $capped = ['maxProperties' => 1];

    expect(refSiblingsChanges(refSiblingsDiff($keeper + $capped, $owner + $capped)))->toBe([
        'GET breaking schema.property-removed owner.properties.since',
        'GET schema.ref-changed owner.$ref',
        'GET schema.required-removed owner.required',
        'PUT schema.property-removed owner.properties.since',
        'PUT schema.ref-changed owner.$ref',
        'PUT schema.required-removed owner.required',
    ]);
});

it('compares a bare pointer against one with a sibling as the move plus the sibling', function () use ($owner, $keeper): void {
    // A bare pointer is an intersection with nothing beside it, so the cap arriving is the one narrowing
    // beside the components compared, never `Owner`'s own keywords leaving.
    $repointed = [
        'GET breaking schema.refinement-narrowed owner.maxProperties',
        'GET schema.property-added owner.properties.since',
        'GET schema.ref-changed owner.$ref',
        'GET schema.required-added owner.required',
        'PUT breaking schema.refinement-narrowed owner.maxProperties',
        'PUT breaking schema.required-added owner.required',
        'PUT schema.property-added owner.properties.since',
        'PUT schema.ref-changed owner.$ref',
    ];

    expect(refSiblingsChanges(refSiblingsDiff($owner, $keeper + ['maxProperties' => 5])))->toBe($repointed)
        // A wrapper round `Keeper` beside the cap is the same schema, and reads the same.
        ->and(refSiblingsChanges(refSiblingsDiff($owner, ['allOf' => [$keeper], 'maxProperties' => 5])))->toBe($repointed)
        // 3.0 ignores a `$ref`'s siblings, so its `Owner` is bare whatever is written beside it, and its
        // export wraps the newer side's `Keeper` with the cap: the same change, the same findings.
        ->and(refSiblingsChanges(refSiblingsDiff($owner + ['maxProperties' => 1], $keeper + ['maxProperties' => 5], '3.0.3', '3.1.1')))->toBe($repointed);
});

it('gives the same findings and verdicts however the repointed intersection is spelled', function () use ($owner, $keeper): void {
    // Bare at 3.1, wrapped at 3.1, and 3.0 against 3.1 (whose export wraps both) are one schema. A wrapper
    // on both sides pairs its branches as written, so its findings sit one `allOf.0` deeper.
    $bare = refSiblingsChanges(refSiblingsDiff($owner + ['maxProperties' => 1], $keeper + ['maxProperties' => 5]));
    $flattened = static fn (array $changes): array => array_map(static fn (string $change): string => str_replace('owner.allOf.0.', 'owner.', $change), $changes);

    expect($flattened(refSiblingsChanges(refSiblingsDiff(['allOf' => [$owner], 'maxProperties' => 1], ['allOf' => [$keeper], 'maxProperties' => 5]))))->toBe($bare)
        ->and($flattened(refSiblingsChanges(refSiblingsDiff(['allOf' => [$owner], 'maxProperties' => 1], $keeper + ['maxProperties' => 5], '3.0.3', '3.1.1'))))->toBe($bare)
        ->and($flattened(refSiblingsChanges(refSiblingsDiff(['allOf' => [$owner], 'maxProperties' => 1], $keeper + ['maxProperties' => 5], '3.1.1', '3.0.3'))))->toBe($bare);

    // An inline side names no component, and the wrapper beside it is the same intersection: a 3.1 author
    // wrote it, or the 3.0 export of `{$ref: Keeper, maxProperties: 5}` did. Compared as written, the
    // inline `id` read as removed and the wrapper's branch as arriving.
    $inline = ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]];
    $direct = refSiblingsChanges(refSiblingsDiff($inline, $keeper + ['maxProperties' => 5]));

    expect(refSiblingsChanges(refSiblingsDiff($inline, ['allOf' => [$keeper], 'maxProperties' => 5])))->toBe($direct)
        ->and(refSiblingsChanges(refSiblingsDiff($inline, $keeper + ['maxProperties' => 5], '3.0.3', '3.1.1')))->toBe($direct)
        ->and(refSiblingsChanges(refSiblingsDiff($keeper + ['maxProperties' => 5], $inline, '3.1.1', '3.0.3')))
        ->toBe(refSiblingsChanges(refSiblingsDiff($keeper + ['maxProperties' => 5], $inline)));
});

it('reports only the sibling where both sides name the same component', function () use ($owner): void {
    // The component's own edits are reported once, where it is diffed by name, so the one change at the
    // position is the cap.
    expect(refSiblingsChanges(refSiblingsDiff($owner + ['maxProperties' => 1], $owner + ['maxProperties' => 5])))->toBe([
        'GET breaking schema.refinement-widened owner.maxProperties',
        'PUT schema.refinement-widened owner.maxProperties',
    ]);
});

it('reads the component an inline shape becomes beside a sibling', function () use ($keeper): void {
    // An inline shape is all pointer part and no siblings, so it is compared with `Keeper` and the cap
    // arrives on its own. As written, `Keeper` went unread and its `since` with it.
    $inline = ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]];

    expect(refSiblingsChanges(refSiblingsDiff($inline, $keeper + ['maxProperties' => 5])))->toBe([
        'GET breaking schema.refinement-narrowed owner.maxProperties',
        'GET schema.property-added owner.properties.since',
        'GET schema.required-added owner.required',
        'PUT breaking schema.refinement-narrowed owner.maxProperties',
        'PUT breaking schema.required-added owner.required',
        'PUT schema.property-added owner.properties.since',
    ]);
});

it('terminates on a component that reaches itself through a pointer with a sibling', function (): void {
    // Each component's `next` is itself, capped, so reading both parts descends into the same pair of
    // pointers it started from: the pair held open for the descent is what stops it, and the move and
    // the cap are reported once at each level.
    $components = [
        'Node' => ['type' => 'object', 'properties' => ['next' => ['$ref' => '#/components/schemas/Node', 'maxProperties' => 3]]],
        'Link' => ['type' => 'object', 'properties' => ['next' => ['$ref' => '#/components/schemas/Link', 'maxProperties' => 2]], 'required' => ['next']],
    ];

    expect(refSiblingsChanges(refSiblingsDiff(['$ref' => '#/components/schemas/Node'], ['$ref' => '#/components/schemas/Link'], components: $components)))->toBe([
        'GET breaking schema.refinement-narrowed owner.properties.next.maxProperties',
        'GET schema.ref-changed owner.$ref',
        'GET schema.ref-changed owner.properties.next.$ref',
        'GET schema.required-added owner.required',
        'PUT breaking schema.refinement-narrowed owner.properties.next.maxProperties',
        'PUT breaking schema.required-added owner.required',
        'PUT schema.ref-changed owner.$ref',
        'PUT schema.ref-changed owner.properties.next.$ref',
    ]);
});
