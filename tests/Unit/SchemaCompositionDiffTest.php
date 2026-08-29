<?php

declare(strict_types=1);

use Docuccino\Core\Diff\DocumentDiffer;
use Docuccino\Core\Diff\RefinementMove;
use Docuccino\Core\Diff\SchemaComparator;
use Docuccino\Core\Diff\SchemaMember;
use Docuccino\Core\Diff\SchemaPolarity;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Draft\SchemaKeywords;

/**
 * The composition and conditional half of the schema diff. `docuccino:diff --enforce` is a release
 * gate, and it read NONE of `allOf oneOf anyOf not if then else contains propertyNames prefixItems
 * patternProperties dependentSchemas dependentRequired unevaluated* $defs` — so the strictest
 * narrowing the language has, a subschema replaced by `false`, passed as safe under any of them, and
 * via the model's own hydration the same edit surfaced as `schema.type-removed`, which was classed
 * non-breaking on both sides at the time. A gate that says "safe" is worse than no gate, because it is trusted.
 *
 * Each keyword's polarity is a recorded decision ({@see SchemaPolarity}) rather than one classification
 * stretched over all of them, so each is pinned here in BOTH directions. The keyword SET is read off
 * {@see SchemaKeywords} rather than listed again, so a keyword the draft model learns fails until
 * somebody decides what it is worth.
 */

/** Every position constant the draft model declares, so a new one is covered without being named. */
function compositionPositions(): array
{
    $positions = [];

    foreach ((new ReflectionClass(SchemaKeywords::class))->getConstants() as $name => $value) {
        if (str_starts_with($name, 'POSITION_') && is_string($value)) {
            $positions[] = $value;
        }
    }

    return $positions;
}

/**
 * Every keyword the draft model gives a subschema position — the set a polarity decision is owed for.
 *
 * @return list<string>
 */
function compositionKeywords(): array
{
    $keywords = [];

    foreach (compositionPositions() as $position) {
        foreach (SchemaKeywords::at($position) as $keyword) {
            $keywords[] = $keyword;
        }
    }

    sort($keywords);

    return $keywords;
}

/** @return array<string, array{string}> */
function compositionKeywordDataset(): array
{
    $rows = [];

    foreach (compositionKeywords() as $keyword) {
        $rows[$keyword] = [$keyword];
    }

    return $rows;
}

/**
 * One schema carrying `$inner` at `$keyword`'s slot: the keyword itself for a single subschema, one
 * member for a map, index 0 for a list, one property's dependency list for `dependentRequired`.
 *
 * @return array<string, mixed>
 */
function compositionProbe(string $keyword, mixed $inner): array
{
    return match (SchemaKeywords::positionOf($keyword)) {
        SchemaKeywords::POSITION_SCHEMA_MAP => [$keyword => ['Inner' => $inner]],
        SchemaKeywords::POSITION_SCHEMA_LIST => [$keyword => [$inner]],
        SchemaKeywords::POSITION_STRING_LIST_MAP => [$keyword => ['a' => $inner]],
        default => [$keyword => $inner],
    };
}

it('records a polarity decision for every keyword the draft model gives a subschema position', function (): void {
    // The guard the fix is owed: the comparator's keyword set is DERIVED from the draft model's own
    // table, so a keyword landing there is a keyword nobody has decided the polarity of until they do.
    $positioned = compositionKeywords();

    [$undecided, $unreachable] = decisionGaps($positioned, SchemaPolarity::decided());

    expect($undecided)->toBe([], 'no polarity decision recorded for: '.implode(', ', $undecided))
        ->and($unreachable)->toBe([], 'a polarity decision for a keyword the draft model does not position: '.implode(', ', $unreachable))
        // A scan that matches nothing must fail rather than pass forever.
        ->and(count($positioned))->toBeGreaterThanOrEqual(20)
        ->and($positioned)->toContain('allOf', 'anyOf', 'oneOf', 'not', 'if', 'then', 'else', 'contains', 'properties', 'dependentRequired');
});

it('refuses a keyword nobody has decided the polarity of', function (): void {
    // The guard above, EXECUTED rather than asserted: hand it the keyword a future draft model learns
    // and it must name it, in either direction.
    $positioned = compositionKeywords();
    $decided = SchemaPolarity::decided();

    expect(decisionGaps([...$positioned, 'aKeywordNobodyDecided'], $decided))
        ->toBe([['aKeywordNobodyDecided'], []])
        ->and(decisionGaps($positioned, [...$decided, 'aDecisionForNoKeyword']))
        ->toBe([[], ['aDecisionForNoKeyword']]);
});

it('reads a keyword nobody has decided conservatively rather than skipping it', function (): void {
    // The runtime half of the same guard, and the reason the split is deliberate: adding a row to the
    // draft model's own table and running this file fails the guard above by NAME while the comparison
    // below still reports the narrowing under that keyword as breaking. The suite tells the author to
    // decide; the gate meanwhile refuses rather than waving it through.
    expect(SchemaPolarity::rule('aKeywordNobodyDecided'))->toBe([
        'polarity' => SchemaPolarity::CONDITIONAL,
        'member' => SchemaMember::EmptySchema,
        'pairsByIndex' => false,
        'code' => null,
    ])
        // Pairing by index is the one pairing fact a keyword decides for itself, and an undecided
        // keyword cannot claim it: nothing knows that its members' positions are their contract.
        ->and(SchemaPolarity::rule('prefixItems')['pairsByIndex'])->toBeTrue()
        ->and(SchemaPolarity::rule('anyOf')['pairsByIndex'])->toBeFalse();
});

it('reports a schema that admits nothing at every subschema position', function (string $keyword): void {
    // `false` is the tightest narrowing the language has and the one value no set of keywords spells,
    // so it is the probe every position owes an answer for. Dataset derived from the draft model's
    // table: a position added there fails here until the comparator descends into it.
    [$old, $new] = SchemaKeywords::positionOf($keyword) === SchemaKeywords::POSITION_STRING_LIST_MAP
        ? [compositionProbe($keyword, []), compositionProbe($keyword, ['b'])]
        : [compositionProbe($keyword, ['type' => 'string']), compositionProbe($keyword, false)];

    $onRequest = schemaDiffCodes($old, $new, request: true);
    $onResponse = schemaDiffCodes($old, $new, request: false);

    expect($onRequest)->not->toBe([], $keyword.' reports nothing')
        ->and($onResponse)->not->toBe([], $keyword.' reports nothing on a response')
        // Reported is half of it: a narrowing nobody classes breaking still passes the gate.
        ->and(str_contains(implode(',', [...$onRequest, ...$onResponse]), '!'))
        ->toBeTrue($keyword.' is reported and never breaking')
        // And the probe is what caused it — a comparison of a document with itself says nothing.
        ->and(schemaDiffCodes($old, $old, request: true))->toBe([]);
})->with(compositionKeywordDataset());

it('classifies each composition and conditional keyword in both directions', function (
    array $old,
    array $new,
    array $onRequest,
    array $onResponse,
): void {
    expect(schemaDiffCodes($old, $new, request: true))->toBe($onRequest, 'request')
        ->and(schemaDiffCodes($old, $new, request: false))->toBe($onResponse, 'response');
})->with([
    // `allOf` is an intersection: a branch added narrows, one removed widens. It is the keyword an
    // overlay narrows with, so it is the highest-value half of the whole fix.
    'allOf: branch added' => [
        ['allOf' => [['type' => 'string']]],
        ['allOf' => [['type' => 'string'], ['minLength' => 3]]],
        ['schema.all-of-branch-added!'], ['schema.all-of-branch-added!'],
    ],
    // A branch removed WIDENS, and a widening is the asymmetric verdict: a writer stays valid while a
    // response reader is handed a value the branch used to exclude and it has no case for. That is the
    // `schema.enum-value-added` argument, and it is the same one `maxItems` raised already got.
    'allOf: branch removed' => [
        ['allOf' => [['type' => 'string'], ['minLength' => 3]]],
        ['allOf' => [['type' => 'string']]],
        ['schema.all-of-branch-removed'], ['schema.all-of-branch-removed!'],
    ],
    'allOf: branch narrowed' => [
        ['allOf' => [['type' => ['string', 'integer']]]],
        ['allOf' => [['type' => 'string']]],
        ['schema.type-narrowed!'], ['schema.type-narrowed!'],
    ],
    // A DIRECT position carries the child's verdict up unchanged, so this row is the `type` comparison's
    // own answer read through `allOf`: a type set that grew is safe for a writer and hands a response
    // reader a value it has no case for.
    'allOf: branch widened' => [
        ['allOf' => [['type' => 'string']]],
        ['allOf' => [['type' => ['string', 'integer']]]],
        ['schema.type-widened'], ['schema.type-widened!'],
    ],
    // A union: a branch removed narrows either way, while one ADDED widens what a request accepts and
    // hands a response reader a shape it has no case for — the `schema.enum-value-added` argument.
    'anyOf: branch added' => [
        ['anyOf' => [['$ref' => '#/components/schemas/A']]],
        ['anyOf' => [['$ref' => '#/components/schemas/A'], ['type' => 'null']]],
        ['schema.any-of-branch-added'], ['schema.any-of-branch-added!'],
    ],
    'anyOf: branch removed' => [
        ['anyOf' => [['$ref' => '#/components/schemas/A'], ['type' => 'null']]],
        ['anyOf' => [['$ref' => '#/components/schemas/A']]],
        ['schema.any-of-branch-removed!'], ['schema.any-of-branch-removed!'],
    ],
    'oneOf: branch added' => [
        ['oneOf' => [['$ref' => '#/components/schemas/A']]],
        ['oneOf' => [['$ref' => '#/components/schemas/A'], ['$ref' => '#/components/schemas/B']]],
        ['schema.one-of-branch-added'], ['schema.one-of-branch-added!'],
    ],
    'oneOf: branch removed' => [
        ['oneOf' => [['$ref' => '#/components/schemas/A'], ['$ref' => '#/components/schemas/B']]],
        ['oneOf' => [['$ref' => '#/components/schemas/A']]],
        ['schema.one-of-branch-removed!'], ['schema.one-of-branch-removed!'],
    ],
    // The KEYWORD arriving is a different statement from a branch arriving, and the one a branch-by-branch
    // reading gets backwards: the old side was not an empty union, it was unconstrained, so a union
    // landing on it narrows both sides. `{type: object}` gaining `anyOf: [{required: [a]}]` starts
    // rejecting the body every writer used to send.
    'anyOf: keyword arrived' => [
        ['type' => 'object'],
        ['type' => 'object', 'anyOf' => [['required' => ['a']]]],
        ['schema.any-of-branch-added!'], ['schema.any-of-branch-added!'],
    ],
    // Leaving is the mirror of `schema.enum-removed`: a request widens, while a response reader loses
    // the closed set of shapes it typed against and can now be handed anything.
    'anyOf: keyword left' => [
        ['type' => 'object', 'anyOf' => [['required' => ['a']]]],
        ['type' => 'object'],
        ['schema.any-of-branch-removed'], ['schema.any-of-branch-removed!'],
    ],
    // The starkest shape of the same defect: read branch-by-branch this was two non-breaking findings
    // while every string over three characters had started being rejected. The `type` leaving the root
    // is a widening of its own — nothing at the root states the instance type any more — so on a
    // response it gates too, and on a request the union arriving is what carries the finding.
    'oneOf: keyword arrived over a plain type' => [
        ['type' => 'string'],
        ['oneOf' => [['type' => 'string', 'maxLength' => 3]]],
        ['schema.one-of-branch-added!', 'schema.type-removed'],
        ['schema.one-of-branch-added!', 'schema.type-removed!'],
    ],
    'oneOf: keyword left' => [
        ['oneOf' => [['$ref' => '#/components/schemas/A'], ['$ref' => '#/components/schemas/B']]],
        ['type' => 'object'],
        ['schema.one-of-branch-removed', 'schema.type-added!'],
        ['schema.one-of-branch-removed!', 'schema.type-added!'],
    ],
    // `allOf` in the same position was already right, which is what made the union rule a bug rather
    // than a missing descent — but it is settled at the keyword now too, as ONE finding rather than one
    // per branch, because what arrived is the intersection and not a branch of one.
    'allOf: keyword arrived' => [
        ['type' => 'object'],
        ['type' => 'object', 'allOf' => [['required' => ['a']], ['maxProperties' => 2]]],
        ['schema.all-of-branch-added!'], ['schema.all-of-branch-added!'],
    ],
    // The whole intersection leaving widens exactly as one branch of it does, so it earns the widening
    // verdict at the keyword too: safe for a writer, and a response reader loses every constraint the
    // intersection was holding.
    'allOf: keyword left' => [
        ['type' => 'object', 'allOf' => [['required' => ['a']], ['maxProperties' => 2]]],
        ['type' => 'object'],
        ['schema.all-of-branch-removed'], ['schema.all-of-branch-removed!'],
    ],
    // `prefixItems` is the one list position where absence IS the empty schema — an unconstrained slot
    // constrains nothing — so the keyword arriving stays the slots arriving, index by index.
    'prefixItems: keyword arrived' => [
        ['type' => 'array'],
        ['type' => 'array', 'prefixItems' => [['type' => 'string']]],
        ['schema.type-added!'], ['schema.type-added!'],
    ],
    // `not` INVERTS, which is why nothing tries to carry the child's verdict up: a type widened under
    // `not` narrows the parent. The code still names what moved; the verdict is conservative.
    'not: added' => [
        ['type' => 'string'],
        ['type' => 'string', 'not' => ['const' => 'x']],
        ['schema.not-added!'], ['schema.not-added!'],
    ],
    // `not` leaving widens: the value it rejected is admitted again. Safe for a writer, and a response
    // reader typed against a set that excluded it can now be handed exactly that value.
    'not: removed' => [
        ['type' => 'string', 'not' => ['const' => 'x']],
        ['type' => 'string'],
        ['schema.not-removed'], ['schema.not-removed!'],
    ],
    'not: subschema widened' => [
        ['not' => ['type' => 'string']],
        ['not' => ['type' => ['string', 'integer']]],
        ['schema.type-widened!'], ['schema.type-widened!'],
    ],
    'not: subschema narrowed' => [
        ['not' => ['type' => ['string', 'integer']]],
        ['not' => ['type' => 'string']],
        ['schema.type-narrowed!'], ['schema.type-narrowed!'],
    ],
    // `if` is the one member of its family with no polarity at all — it moves instances between the
    // `then` and `else` branches — so both directions are breaking by decision.
    'if: added' => [
        ['type' => 'object'],
        ['type' => 'object', 'if' => ['required' => ['a']]],
        ['schema.required-added!'], ['schema.required-added!'],
    ],
    'if: removed' => [
        ['type' => 'object', 'if' => ['required' => ['a']]],
        ['type' => 'object'],
        ['schema.required-removed!'], ['schema.required-removed!'],
    ],
    // `then` and `else` are DIRECT, which is a correction to the family reading: `(A ∧ B) ∨ ¬A` and
    // `A ∨ (¬A ∧ C)` both narrow when B or C narrows, so their verdicts are real rather than conservative.
    'then: narrowed' => [
        ['if' => ['required' => ['a']], 'then' => ['type' => ['string', 'integer']]],
        ['if' => ['required' => ['a']], 'then' => ['type' => 'string']],
        ['schema.type-narrowed!'], ['schema.type-narrowed!'],
    ],
    'then: widened' => [
        ['if' => ['required' => ['a']], 'then' => ['type' => 'string']],
        ['if' => ['required' => ['a']], 'then' => ['type' => ['string', 'integer']]],
        ['schema.type-widened'], ['schema.type-widened!'],
    ],
    'else: narrowed' => [
        ['if' => ['required' => ['a']], 'else' => ['type' => ['string', 'integer']]],
        ['if' => ['required' => ['a']], 'else' => ['type' => 'string']],
        ['schema.type-narrowed!'], ['schema.type-narrowed!'],
    ],
    'else: widened' => [
        ['if' => ['required' => ['a']], 'else' => ['type' => 'string']],
        ['if' => ['required' => ['a']], 'else' => ['type' => ['string', 'integer']]],
        ['schema.type-widened'], ['schema.type-widened!'],
    ],
    // `contains` demands a match, so no `contains` and `contains: {}` say opposite things and presence
    // is a claim of its own.
    'contains: added' => [
        ['type' => 'array'],
        ['type' => 'array', 'contains' => ['type' => 'string']],
        ['schema.contains-added!'], ['schema.contains-added!'],
    ],
    // …and one that WAS asserting something leaving is a widening like any other: the array need no
    // longer hold a matching element, so a reader that typed against there being one may now get none.
    'contains: removed' => [
        ['type' => 'array', 'contains' => ['type' => 'string']],
        ['type' => 'array'],
        ['schema.contains-removed'], ['schema.contains-removed!'],
    ],
    // …but at `minContains: 0` it asserts nothing, so it can arrive without narrowing anything.
    'contains: added with minContains 0' => [
        ['type' => 'array'],
        ['type' => 'array', 'contains' => ['type' => 'string'], 'minContains' => 0],
        ['schema.contains-added'], ['schema.contains-added'],
    ],
    // Unless a cap arrives with it: `maxContains` asserts on its own, so `minContains: 0` beside it says
    // only that no element need match — not that nothing is being claimed. `["a","b","c"]` was accepted
    // and now is not.
    'contains: added with minContains 0 and a cap' => [
        ['type' => 'array'],
        ['type' => 'array', 'contains' => ['type' => 'string'], 'minContains' => 0, 'maxContains' => 2],
        ['schema.contains-added!'], ['schema.contains-added!'],
    ],
    'contains: subschema narrowed' => [
        ['contains' => ['type' => ['string', 'integer']]],
        ['contains' => ['type' => 'string']],
        ['schema.type-narrowed!'], ['schema.type-narrowed!'],
    ],
    // A tuple slot is its own contract, so the index pairs: appending one constrains a position that
    // was unconstrained, and reordering two is two positions changing.
    'prefixItems: slot added' => [
        ['prefixItems' => [['type' => 'string']]],
        ['prefixItems' => [['type' => 'string'], ['type' => 'integer']]],
        ['schema.type-added!'], ['schema.type-added!'],
    ],
    // A slot dropped leaves that tuple position unconstrained, which is the `type` constraint leaving —
    // safe for a writer, and a response reader who typed the third element `integer` may now get
    // anything at all.
    'prefixItems: slot removed' => [
        ['prefixItems' => [['type' => 'string'], ['type' => 'integer']]],
        ['prefixItems' => [['type' => 'string']]],
        ['schema.type-removed'], ['schema.type-removed!'],
    ],
    'prefixItems: reordered' => [
        ['prefixItems' => [['type' => 'string'], ['type' => 'integer']]],
        ['prefixItems' => [['type' => 'integer'], ['type' => 'string']]],
        ['schema.type-changed!', 'schema.type-changed!'], ['schema.type-changed!', 'schema.type-changed!'],
    ],
    // Every other map and single position: an absent member says what the empty schema says there, so
    // a member arriving IS the constraint arriving and needs no code of its own.
    'patternProperties: member added' => [
        ['type' => 'object'],
        ['type' => 'object', 'patternProperties' => ['^x-' => ['type' => 'string']]],
        ['schema.type-added!'], ['schema.type-added!'],
    ],
    'patternProperties: member removed' => [
        ['type' => 'object', 'patternProperties' => ['^x-' => ['type' => 'string']]],
        ['type' => 'object'],
        ['schema.type-removed'], ['schema.type-removed!'],
    ],
    'dependentSchemas: member added' => [
        ['type' => 'object'],
        ['type' => 'object', 'dependentSchemas' => ['a' => ['required' => ['b']]]],
        ['schema.required-added!'], ['schema.required-added'],
    ],
    'propertyNames: narrowed' => [
        ['propertyNames' => ['type' => ['string', 'integer']]],
        ['propertyNames' => ['type' => 'string']],
        ['schema.type-narrowed!'], ['schema.type-narrowed!'],
    ],
    'unevaluatedProperties: closed' => [
        ['type' => 'object'],
        ['type' => 'object', 'unevaluatedProperties' => false],
        ['schema.always-invalid-added!'], ['schema.always-invalid-added!'],
    ],
    'unevaluatedItems: closed' => [
        ['type' => 'array'],
        ['type' => 'array', 'unevaluatedItems' => false],
        ['schema.always-invalid-added!'], ['schema.always-invalid-added!'],
    ],
    'additionalItems: closed' => [
        ['type' => 'array'],
        ['type' => 'array', 'additionalItems' => false],
        ['schema.always-invalid-added!'], ['schema.always-invalid-added!'],
    ],
    'contentSchema: narrowed' => [
        ['type' => 'string', 'contentSchema' => ['type' => ['string', 'integer']]],
        ['type' => 'string', 'contentSchema' => ['type' => 'string']],
        ['schema.type-narrowed!'], ['schema.type-narrowed!'],
    ],
    // `dependentRequired` narrows what a request accepts exactly as `required` does, and is reported
    // the same way — a report rather than a verdict on the response side.
    'dependentRequired: dependency added' => [
        ['type' => 'object'],
        ['type' => 'object', 'dependentRequired' => ['a' => ['b']]],
        ['schema.dependent-required-added!'], ['schema.dependent-required-added'],
    ],
    'dependentRequired: dependency removed' => [
        ['type' => 'object', 'dependentRequired' => ['a' => ['b']]],
        ['type' => 'object'],
        ['schema.dependent-required-removed'], ['schema.dependent-required-removed'],
    ],
    'dependentRequired: one swapped for another' => [
        ['dependentRequired' => ['a' => ['b']]],
        ['dependentRequired' => ['a' => ['c']]],
        ['schema.dependent-required-added!', 'schema.dependent-required-removed'],
        ['schema.dependent-required-added', 'schema.dependent-required-removed'],
    ],
    // A `$defs` member is a STORE rather than an assertion: its polarity is whatever the `$ref`s
    // naming it are worth, which this comparison does not resolve — so arriving is nothing and leaving
    // may dangle a ref.
    '$defs: member added' => [
        ['type' => 'object'],
        ['type' => 'object', '$defs' => ['X' => ['type' => 'string']]],
        ['schema.definition-added'], ['schema.definition-added'],
    ],
    '$defs: member removed' => [
        ['type' => 'object', '$defs' => ['X' => ['type' => 'string']]],
        ['type' => 'object'],
        ['schema.definition-removed!'], ['schema.definition-removed!'],
    ],
    '$defs: member widened' => [
        ['$defs' => ['X' => ['type' => 'string']]],
        ['$defs' => ['X' => ['type' => ['string', 'integer']]]],
        ['schema.type-widened!'], ['schema.type-widened!'],
    ],
    'definitions: draft-07 spelling reads the same' => [
        ['definitions' => ['X' => ['type' => 'string']]],
        ['definitions' => ['X' => ['type' => ['string', 'integer']]]],
        ['schema.type-widened!'], ['schema.type-widened!'],
    ],
]);

it('leaves an annotation edit under an indeterminate position alone', function (array $old, array $new): void {
    // The one exception to the conservative verdict: an annotation-only keyword says what a value MEANS
    // and nothing about what it may be, so editing one gates nothing under any versioning policy — at
    // `not` and `$defs` as much as anywhere else. Without this a doc edit inside a `not` fails a
    // release gate, which is how a channel stops being read.
    expect(schemaDiffCodes($old, $new, request: true))->toBe(['schema.annotation-changed'])
        ->and(schemaDiffCodes($old, $new, request: false))->toBe(['schema.annotation-changed']);
})->with([
    'under not' => [
        ['not' => ['type' => 'string', 'description' => 'was']],
        ['not' => ['type' => 'string', 'description' => 'now']],
    ],
    'under if' => [
        ['if' => ['type' => 'string', 'description' => 'was']],
        ['if' => ['type' => 'string', 'description' => 'now']],
    ],
    'under $defs' => [
        ['$defs' => ['X' => ['type' => 'string', 'title' => 'Was']]],
        ['$defs' => ['X' => ['type' => 'string', 'title' => 'Now']]],
    ],
]);

it('pairs a list branch by what it is, never by where it sits', function (): void {
    // Without an identity rule, reordering a union reads as rewriting every branch — noise that would
    // swamp every real finding. The ladder is ComponentNames' applied to a list: identity, then the
    // component a branch names, then its content.
    $refs = static fn (string ...$names): array => ['oneOf' => array_map(
        static fn (string $name): array => ['$ref' => '#/components/schemas/'.$name],
        $names,
    )];

    expect(schemaDiffCodes($refs('A', 'B', 'C'), $refs('C', 'A', 'B'), request: true))->toBe([])
        ->and(schemaDiffCodes($refs('A', 'B'), $refs('A', 'B'), request: false))->toBe([])
        // A branch replaced is two branches, never one edited: pairing the leftovers would publish
        // `schema.ref-changed` — non-breaking — over a union branch no existing reader has a case for.
        ->and(schemaDiffCodes($refs('A', 'B'), $refs('A', 'C'), request: false))
        ->toBe(['schema.one-of-branch-added!', 'schema.one-of-branch-removed!'])
        // On a request the branch that WENT is still breaking — a writer's valid body is now refused —
        // and only the one that arrived stands down.
        ->and(schemaDiffCodes($refs('A', 'B'), $refs('A', 'C'), request: true))
        ->toBe(['schema.one-of-branch-added', 'schema.one-of-branch-removed!']);
});

it('pairs an identified branch that both moved and changed', function (): void {
    // The top rung: a branch carrying a Docuccino id is the same branch wherever it sits and whatever
    // else moved inside it, which is the identity every other pairing in the diff already runs on.
    $branch = static fn (string $id, string $type): array => [
        'x-docuccino' => ['id' => $id],
        'type' => $type,
    ];

    $old = ['anyOf' => [$branch('sch:v1:aaaaaaaaaaaaaaaa', 'string'), $branch('sch:v1:bbbbbbbbbbbbbbbb', 'integer')]];
    $new = ['anyOf' => [$branch('sch:v1:bbbbbbbbbbbbbbbb', 'integer'), $branch('sch:v1:aaaaaaaaaaaaaaaa', 'boolean')]];

    $changes = (new SchemaComparator)->compare($old, $new, 'S', 'sch:v1:0000000000000000', request: true);

    expect(array_map(static fn ($c): string => $c->code.' @'.$c->path, $changes))
        ->toBe(['schema.type-changed @S.anyOf.1.type']);
});

it('reads an inline branch edited in place as one branch changing', function (): void {
    // The last rung, and the only inexact one: ONE branch left over on each side, neither naming a
    // component, is one inline branch edited — the `allOf: [$ref, {inline extension}]` shape a
    // problem+json body is published as. Reporting it as a branch gone and another arrived would fail
    // a release gate over a property added.
    $body = static fn (array $properties): array => ['allOf' => [
        ['$ref' => '#/components/schemas/ProblemDetails'],
        ['type' => 'object', 'properties' => $properties],
    ]];

    $changes = (new SchemaComparator)->compare(
        $body(['detail' => ['type' => 'string']]),
        $body(['detail' => ['type' => 'string'], 'pointer' => ['type' => 'string']]),
        'S',
        'sch:v1:0000000000000000',
        request: false,
    );

    expect(array_map(static fn ($c): string => $c->code.($c->breaking ? '!' : '').' @'.$c->path, $changes))
        ->toBe(['schema.property-added @S.allOf.1.properties.pointer']);
});

it('reads the contains bounds beside the contains they bound', function (array $old, array $new, array $onRequest, array $onResponse): void {
    // A bound moved is a direction like any other, and it takes the verdict every direction takes: a
    // bound TIGHTENED gates both sides, a bound RELAXED gates the response alone. The rows below used
    // to assert the two sides were equal, on the claim that a bound constrains the array either way it
    // is read — which is true of what the keyword MEANS and says nothing about who is broken by it, and
    // is false two files away, where `maxItems` raised has always been breaking on a response.
    expect(schemaDiffCodes($old, $new, request: true))->toBe($onRequest, 'request')
        ->and(schemaDiffCodes($old, $new, request: false))->toBe($onResponse, 'response');
})->with([
    'minContains raised' => [
        ['contains' => ['type' => 'string'], 'minContains' => 1],
        ['contains' => ['type' => 'string'], 'minContains' => 2],
        ['schema.contains-bound-narrowed!'], ['schema.contains-bound-narrowed!'],
    ],
    'minContains lowered' => [
        ['contains' => ['type' => 'string'], 'minContains' => 2],
        ['contains' => ['type' => 'string'], 'minContains' => 1],
        ['schema.contains-bound-widened'], ['schema.contains-bound-widened!'],
    ],
    // Absent is 1 — the keyword's own default, which is what makes `minContains: 0` a real statement.
    'minContains dropped to zero' => [
        ['contains' => ['type' => 'string']],
        ['contains' => ['type' => 'string'], 'minContains' => 0],
        ['schema.contains-bound-widened'], ['schema.contains-bound-widened!'],
    ],
    'minContains restated' => [
        ['contains' => ['type' => 'string']],
        ['contains' => ['type' => 'string'], 'minContains' => 1],
        [], [],
    ],
    // No cap is no bound at all, so one arriving narrows however high it is set.
    'maxContains capped' => [
        ['contains' => ['type' => 'string']],
        ['contains' => ['type' => 'string'], 'maxContains' => 9],
        ['schema.contains-bound-narrowed!'], ['schema.contains-bound-narrowed!'],
    ],
    'maxContains lowered' => [
        ['contains' => ['type' => 'string'], 'maxContains' => 9],
        ['contains' => ['type' => 'string'], 'maxContains' => 2],
        ['schema.contains-bound-narrowed!'], ['schema.contains-bound-narrowed!'],
    ],
    'maxContains raised' => [
        ['contains' => ['type' => 'string'], 'maxContains' => 2],
        ['contains' => ['type' => 'string'], 'maxContains' => 9],
        ['schema.contains-bound-widened'], ['schema.contains-bound-widened!'],
    ],
    'maxContains uncapped' => [
        ['contains' => ['type' => 'string'], 'maxContains' => 2],
        ['contains' => ['type' => 'string']],
        ['schema.contains-bound-widened'], ['schema.contains-bound-widened!'],
    ],
    // Both are inert with no `contains` beside them, and where `contains` itself moves, THAT is the
    // change: a bound reported next to it would be a second finding for one edit.
    'a bound with no contains at all' => [
        ['type' => 'array', 'minContains' => 1],
        ['type' => 'array', 'minContains' => 4],
        [], [],
    ],
    'a bound arriving with the contains it bounds' => [
        ['type' => 'array'],
        ['type' => 'array', 'contains' => ['type' => 'string'], 'minContains' => 4],
        ['schema.contains-added!'], ['schema.contains-added!'],
    ],
]);

it('names a union branch through the path a diff actually runs', function (): void {
    // The end-to-end claim: this is the edit `--enforce` used to pass as safe. A response that could
    // return a Widget stops being able to, and the gate now says so, at the path a reviewer can find.
    $document = static fn (array $branches): UirDocument => UirDocument::fromArray([
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'API', 'version' => '1.0.0'],
        'paths' => ['/things' => ['get' => [
            'x-docuccino' => ['id' => 'op:v1:aaaaaaaaaaaaaaaa'],
            'operationId' => 'things.index',
            'responses' => ['200' => [
                'x-docuccino' => ['id' => 'res:v1:bbbbbbbbbbbbbbbb'],
                'description' => 'ok',
                'content' => ['application/json' => ['schema' => ['oneOf' => $branches]]],
            ]],
        ]]],
        'components' => ['schemas' => [
            'Gadget' => ['x-docuccino' => ['id' => 'sch:v1:cccccccccccccccc'], 'type' => 'object'],
            'Widget' => ['x-docuccino' => ['id' => 'sch:v1:dddddddddddddddd'], 'type' => 'object'],
        ]],
    ]);

    $both = [['$ref' => '#/components/schemas/Gadget'], ['$ref' => '#/components/schemas/Widget']];
    $changeset = (new DocumentDiffer)->diff($document($both), $document([$both[0]]));

    expect(array_map(static fn ($c): string => $c->code, $changeset->changes))
        ->toContain('schema.one-of-branch-removed')
        ->and($changeset->isBreaking())->toBeTrue();

    foreach ($changeset->changes as $change) {
        if ($change->code === 'schema.one-of-branch-removed') {
            expect($change->path)->toBe('GET /things responses 200 application/json schema.oneOf.1');
        }
    }
});

it('reads a value that is no list as no branches at all', function (mixed $garbage): void {
    // A comparison runs on whatever an artifact holds, and an `allOf` that is not a list is not a schema
    // either — so it reads as absent, which is the widening the canonicalizer publishes for it. Reading
    // it as ONE branch would report a narrowing that is not in the document.
    expect(schemaDiffCodes(['allOf' => $garbage], ['allOf' => $garbage], request: true))->toBe([])
        ->and(schemaDiffCodes(['allOf' => [['type' => 'string']]], ['allOf' => $garbage], request: true))
        ->toBe(['schema.all-of-branch-removed'])
        ->and(schemaDiffCodes(['allOf' => $garbage], ['allOf' => [['type' => 'string']]], request: true))
        ->toBe(['schema.all-of-branch-added!']);
})->with([
    'an object' => [['not' => 'a list']],
    'a string' => ['nonsense'],
    'a number' => [7],
    'null' => [null],
]);

it('reads a map position that is no map as carrying no members', function (mixed $garbage): void {
    // The same for the map positions and for `dependentRequired`, whose members are string lists: a
    // member nothing can read is a member nobody wrote.
    expect(schemaDiffCodes(['patternProperties' => $garbage], ['patternProperties' => $garbage], request: true))->toBe([])
        ->and(schemaDiffCodes(['dependentRequired' => $garbage], ['dependentRequired' => $garbage], request: true))->toBe([])
        // And a dependency list that is no list of strings leaves the property with no dependencies.
        ->and(schemaDiffCodes(['dependentRequired' => ['a' => $garbage]], ['dependentRequired' => ['a' => ['b']]], request: true))
        ->toBe(['schema.dependent-required-added!']);
})->with([
    'a map of non-schemas' => [['x' => 7]],
    'a string' => ['nonsense'],
    'a number' => [7],
    'null' => [null],
]);

it('never lets a position with no computable polarity report a change as safe', function (): void {
    // The decision the whole fix turns on, swept rather than sampled: where the direction cannot be
    // computed the verdict is breaking, on BOTH sides, for every edit shape a subschema can take. The
    // set is read off the rules table, so a keyword moved to CONDITIONAL is covered without being named.
    $conditional = array_values(array_filter(
        SchemaPolarity::decided(),
        static fn (string $keyword): bool => SchemaPolarity::rule($keyword)['polarity'] === SchemaPolarity::CONDITIONAL,
    ));

    // Anti-vacuity: an empty set would agree with everything below and prove nothing.
    expect($conditional)->toContain('if', '$defs', 'definitions');

    $edits = [
        'narrowed' => ['type' => 'string'],
        'widened' => ['type' => ['string', 'integer', 'null']],
        'retyped' => ['type' => 'integer'],
        'untyped' => [],
        'admits nothing' => false,
        'admits everything' => true,
        'constrained' => ['type' => ['string', 'integer'], 'required' => ['a']],
        'unconstrained' => ['type' => ['string', 'integer'], 'enum' => null],
    ];

    foreach ($conditional as $keyword) {
        foreach ($edits as $label => $inner) {
            $old = compositionProbe($keyword, ['type' => ['string', 'integer']]);
            $new = compositionProbe($keyword, $inner);

            foreach ([true, false] as $request) {
                foreach ((new SchemaComparator)->compare($old, $new, 'S', 'sch:v1:0000000000000000', $request) as $change) {
                    expect($change->breaking)->toBeTrue(
                        $keyword.' · '.$label.' · '.($request ? 'request' : 'response').' · '.$change->code,
                    );
                }
            }
        }
    }
});

it('states the one place an indeterminate position stands a change down, and why', function (): void {
    // Two exceptions, both decisions rather than gaps. An annotation-only edit moves no contract at any
    // position, so forcing it breaking would fail a gate over a rewritten description — which is how a
    // channel stops being read. And a `$defs` member ARRIVING is not an assertion arriving: nothing can
    // name a definition that did not also change, and whatever named it is reported where it changed.
    expect(schemaDiffCodes(
        ['$defs' => ['X' => ['type' => 'string', 'description' => 'was']]],
        ['$defs' => ['X' => ['type' => 'string', 'description' => 'now']]],
        request: true,
    ))->toBe(['schema.annotation-changed'])
        ->and(schemaDiffCodes(['type' => 'object'], ['type' => 'object', '$defs' => ['X' => ['type' => 'string']]], request: true))
        ->toBe(['schema.definition-added'])
        // Leaving is the half that gates: a `$ref` naming the member is left pointing at nothing.
        ->and(schemaDiffCodes(['type' => 'object', '$defs' => ['X' => ['type' => 'string']]], ['type' => 'object'], request: true))
        ->toBe(['schema.definition-removed!']);
});

it('answers the presence direction for every member kind there is, at the keyword and at a member', function (): void {
    // The two lookup tables behind every `-added`/`-removed` code, over EVERY kind rather than a sample.
    // They answer in DIRECTIONS rather than verdicts, because what a direction is worth is one rule the
    // comparator applies to all three tables — so a kind here cannot quietly grow a verdict rule of its
    // own, which is exactly how `not` leaving and a `maxContains` raised came to disagree.
    //
    // The two tables differ on the one kind that matters: a union KEYWORD arriving is the union
    // constraint arriving where there was none, while a union BRANCH arriving widens a union that was
    // already there. Each row is [arriving, leaving].
    $keywordMoves = [
        // A constraint or a union arriving narrows whichever way the schema is read; either leaving widens.
        SchemaMember::Constraint->value => [RefinementMove::Narrowed, RefinementMove::Widened],
        SchemaMember::Union->value => [RefinementMove::Narrowed, RefinementMove::Widened],
        // `contains` moves only while it asserts something ($asserts, from its own bounds).
        SchemaMember::Bounded->value => [RefinementMove::Narrowed, RefinementMove::Widened],
        // The kinds that report per member never reach this table, and a position nobody decided is the
        // one that must not guess: a direction nothing can compute, which gates both ways.
        SchemaMember::Store->value => [RefinementMove::Incomparable, RefinementMove::Incomparable],
        SchemaMember::Property->value => [RefinementMove::Incomparable, RefinementMove::Incomparable],
        SchemaMember::Required->value => [RefinementMove::Incomparable, RefinementMove::Incomparable],
        SchemaMember::EmptySchema->value => [RefinementMove::Incomparable, RefinementMove::Incomparable],
    ];

    $memberMoves = [
        // A branch of an intersection arriving narrows; going widens.
        SchemaMember::Constraint->value => ['request' => [RefinementMove::Narrowed, RefinementMove::Widened], 'response' => [RefinementMove::Narrowed, RefinementMove::Widened]],
        // A union branch going narrows; one arriving widens, which is safe for a writer and hands a
        // reader a shape it has no case for.
        SchemaMember::Union->value => ['request' => [RefinementMove::Widened, RefinementMove::Narrowed], 'response' => [RefinementMove::Widened, RefinementMove::Narrowed]],
        // A definition arriving moves nothing — nothing could name it — while one leaving may dangle a
        // `$ref` this comparison does not resolve, which is a direction it cannot compute.
        SchemaMember::Store->value => ['request' => [RefinementMove::Unchanged, RefinementMove::Incomparable], 'response' => [RefinementMove::Unchanged, RefinementMove::Incomparable]],
        // The one audience-relative row: a `dependentRequired` entry is an obligation on a WRITER, so a
        // request reads it as `required` does and a response — no writer, usage context unknown — reads
        // it as moving nothing. That keeps the judgment call `schema.required-added` makes in ONE place
        // instead of adding a second verdict rule beside the shared one.
        SchemaMember::Required->value => ['request' => [RefinementMove::Narrowed, RefinementMove::Widened], 'response' => [RefinementMove::Unchanged, RefinementMove::Unchanged]],
        // `contains` holds one subschema and `properties` has a comparison of its own, so neither has
        // members reaching here; an EMPTY position's members fall out of the keyword comparison.
        SchemaMember::Bounded->value => ['request' => [RefinementMove::Incomparable, RefinementMove::Incomparable], 'response' => [RefinementMove::Incomparable, RefinementMove::Incomparable]],
        SchemaMember::Property->value => ['request' => [RefinementMove::Incomparable, RefinementMove::Incomparable], 'response' => [RefinementMove::Incomparable, RefinementMove::Incomparable]],
        SchemaMember::EmptySchema->value => ['request' => [RefinementMove::Incomparable, RefinementMove::Incomparable], 'response' => [RefinementMove::Incomparable, RefinementMove::Incomparable]],
    ];

    // A dataset only proves the rows it lists, so the kinds come from the enum and this fails short.
    // Compared as sets: which kinds exist is the fact, and the order they are declared in is not one.
    $kinds = array_map(static fn (SchemaMember $kind): string => $kind->value, SchemaMember::cases());
    sort($kinds);

    foreach (['keyword' => $keywordMoves, 'member' => $memberMoves] as $table => $moves) {
        $listed = array_keys($moves);
        sort($listed);

        expect($listed)->toBe($kinds, $table.' table')
            ->and(count($kinds))->toBeGreaterThanOrEqual(5);
    }

    foreach (SchemaMember::cases() as $member) {
        [$arriving, $leaving] = $keywordMoves[$member->value];

        expect(SchemaPolarity::keywordPresence($member, arriving: true, asserts: true))
            ->toBe($arriving, $member->value.' keyword arriving')
            ->and(SchemaPolarity::keywordPresence($member, arriving: false, asserts: true))
            ->toBe($leaving, $member->value.' keyword leaving');

        foreach (['request' => true, 'response' => false] as $side => $request) {
            [$arriving, $leaving] = $memberMoves[$member->value][$side];

            expect(SchemaPolarity::memberPresence($member, arriving: true, request: $request))
                ->toBe($arriving, $member->value.' member arriving on a '.$side)
                ->and(SchemaPolarity::memberPresence($member, arriving: false, request: $request))
                ->toBe($leaving, $member->value.' member leaving on a '.$side);
        }
    }

    // `contains` is the one row `$asserts` moves: with `minContains: 0` and no cap it arrives asserting
    // nothing, so nothing moves in either direction. A kind nobody has given an answer cannot be spelled
    // at all — the enum is closed and every match over it carries no default — so the unknown-entry
    // contract is PHPStan's rather than a row.
    expect(SchemaPolarity::keywordPresence(SchemaMember::Bounded, arriving: true, asserts: false))->toBe(RefinementMove::Unchanged)
        ->and(SchemaPolarity::keywordPresence(SchemaMember::Bounded, arriving: false, asserts: false))->toBe(RefinementMove::Unchanged);
});
