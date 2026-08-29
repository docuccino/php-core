<?php

declare(strict_types=1);

use Docuccino\Core\Diff\DocumentDiffer;
use Docuccino\Core\Diff\RefinementKind;
use Docuccino\Core\Diff\RefinementMove;
use Docuccino\Core\Diff\SchemaRefinement;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Draft\SchemaKeywords;

/**
 * The refinement half of the schema diff. `docuccino:diff --enforce` is a release gate, and it read
 * none of `maxLength minLength pattern const minimum maximum exclusive* multipleOf min/maxItems
 * uniqueItems min/maxProperties content*` — so `{type: string, maxLength: 100}` becoming
 * `{type: string, maxLength: 3}` on a request reported NO CHANGES, and every client that validates
 * before sending started refusing payloads the server had accepted the day before.
 *
 * The direction is a recorded decision per keyword ({@see SchemaRefinement}) rather than one rule
 * stretched over all of them, so each is pinned here in both directions. The keyword SET is read off
 * {@see SchemaKeywords} rather than listed again — and that is the half the composition guard could
 * never cover, because a refinement occupies no subschema position and so is invisible to a scan
 * keyed to those.
 */

/** Every refinement the draft model knows — the set a direction decision is owed for. */
function refinementKeywords(): array
{
    $keywords = SchemaKeywords::refinements();
    sort($keywords);

    return $keywords;
}

/** Every refinement this comparison answers for itself — the rest name where they are read instead. */
function refinementsCompared(): array
{
    return array_values(array_filter(
        refinementKeywords(),
        static fn (string $keyword): bool => ! in_array(
            SchemaRefinement::rule($keyword)['kind'],
            [RefinementKind::Elsewhere, RefinementKind::Undecided],
            true,
        ),
    ));
}

/** @return array<string, array{string}> */
function refinementComparedDataset(): array
{
    $rows = [];

    foreach (refinementsCompared() as $keyword) {
        $rows[$keyword] = [$keyword];
    }

    return $rows;
}

/** A value the keyword's own kind can read, so a probe needs no per-keyword table of its own. */
function refinementProbeValue(string $keyword): mixed
{
    return match (SchemaRefinement::rule($keyword)['kind']) {
        RefinementKind::Flag => true,
        RefinementKind::Opaque => 'x',
        default => 3,
    };
}

it('records a direction decision for every refinement the draft model knows', function (): void {
    // The guard the fix is owed, and the one the composition work could not have provided: refinements
    // are not subschema positions, so a scan keyed to those walks straight past every keyword here.
    $known = refinementKeywords();

    [$undecided, $unreachable] = decisionGaps($known, SchemaRefinement::decided());

    expect($undecided)->toBe([], 'no direction decision recorded for: '.implode(', ', $undecided))
        ->and($unreachable)->toBe([], 'a direction decision for a keyword the draft model does not know: '.implode(', ', $unreachable))
        // A scan that matches nothing must fail rather than pass forever.
        ->and(count($known))->toBeGreaterThanOrEqual(18)
        ->and($known)->toContain('maxLength', 'minLength', 'pattern', 'const', 'minimum', 'maximum')
        ->and($known)->toContain('exclusiveMinimum', 'exclusiveMaximum', 'multipleOf', 'uniqueItems')
        ->and($known)->toContain('minItems', 'maxItems', 'minProperties', 'maxProperties')
        ->and($known)->toContain('contentEncoding', 'contentMediaType');
});

it('refuses a refinement keyword nobody has decided', function (): void {
    // The guard above, EXECUTED rather than asserted: hand it the keyword a future draft model learns
    // and it must name it, in either direction.
    $known = refinementKeywords();
    $decided = SchemaRefinement::decided();

    expect(decisionGaps([...$known, 'aRefinementNobodyDecided'], $decided))
        ->toBe([['aRefinementNobodyDecided'], []])
        ->and(decisionGaps($known, [...$decided, 'aDecisionForNoRefinement']))
        ->toBe([[], ['aDecisionForNoRefinement']]);
});

it('reads a refinement nobody has decided conservatively rather than skipping it', function (): void {
    // The runtime half of the same guard, and the reason the split is deliberate: adding a row to the
    // draft model's own table and running this file fails the guard above by NAME while the comparison
    // still refuses the change. The suite tells the author to decide; the gate meanwhile does not guess.
    expect(SchemaRefinement::rule('aRefinementNobodyDecided')['kind'])->toBe(RefinementKind::Undecided)
        ->and(SchemaRefinement::move('aRefinementNobodyDecided', ['aRefinementNobodyDecided' => 1], ['aRefinementNobodyDecided' => 2]))
        ->toBe(RefinementMove::Incomparable)
        // …and a keyword that is no refinement at all is nobody's decision to make here.
        ->and(SchemaRefinement::kindOf('aRefinementNobodyDecided'))->toBeNull()
        ->and(SchemaRefinement::kindOf('type'))->toBeNull()
        ->and(SchemaRefinement::kindOf('maxLength'))->toBe(RefinementKind::UpperBound);
});

it('records the kind and the absent reading of every refinement', function (): void {
    // The whole table, every row, because a mapping table proves only the rows a dataset lists — and
    // the set is read off the source of truth, so a keyword added there fails this until it has a row.
    $rules = [
        'maxLength' => [RefinementKind::UpperBound, null],
        'maxItems' => [RefinementKind::UpperBound, null],
        'maxProperties' => [RefinementKind::UpperBound, null],
        'maximum' => [RefinementKind::UpperBound, null],
        'exclusiveMaximum' => [RefinementKind::UpperBound, null],
        // The three floors a keyword restates rather than moves: no string is shorter than no
        // characters, and no array or object holds fewer than no members.
        'minLength' => [RefinementKind::LowerBound, 0.0],
        'minItems' => [RefinementKind::LowerBound, 0.0],
        'minProperties' => [RefinementKind::LowerBound, 0.0],
        'minimum' => [RefinementKind::LowerBound, null],
        'exclusiveMinimum' => [RefinementKind::LowerBound, null],
        'multipleOf' => [RefinementKind::Divisor, null],
        'uniqueItems' => [RefinementKind::Flag, null],
        'pattern' => [RefinementKind::Opaque, null],
        'const' => [RefinementKind::Opaque, null],
        'contentEncoding' => [RefinementKind::Opaque, null],
        'contentMediaType' => [RefinementKind::Opaque, null],
        'enum' => [RefinementKind::Elsewhere, null],
        'format' => [RefinementKind::Elsewhere, null],
        'contentSchema' => [RefinementKind::Elsewhere, null],
        'minContains' => [RefinementKind::Elsewhere, null],
        'maxContains' => [RefinementKind::Elsewhere, null],
    ];

    $listed = array_keys($rules);
    sort($listed);

    expect($listed)->toBe(refinementKeywords());

    foreach ($rules as $keyword => [$kind, $absent]) {
        $rule = SchemaRefinement::rule($keyword);

        expect($rule['kind'])->toBe($kind, $keyword.' kind')
            ->and($rule['absent'])->toBe($absent, $keyword.' absent');
    }

    // Only the two dialect-split keywords read a boolean as anything but nonsense.
    $draft04 = array_values(array_filter(
        refinementKeywords(),
        static fn (string $keyword): bool => SchemaRefinement::rule($keyword)['draft04Boolean'],
    ));

    expect($draft04)->toBe(['exclusiveMaximum', 'exclusiveMinimum']);
});

it('reports and gates a refinement arriving, and reports one leaving, at every keyword it compares', function (string $keyword): void {
    // The sweep the fix turns on, derived rather than sampled: a keyword with a decision the comparator
    // never reads would pass every hand-written row below and fail here. A refinement ARRIVING narrows
    // what was unconstrained; one LEAVING widens, which is safe for a writer and hands a response
    // reader a value it has no case for — the `schema.enum-value-added` argument exactly.
    $bare = ['type' => 'string'];
    $refined = ['type' => 'string', $keyword => refinementProbeValue($keyword)];

    expect(schemaDiffCodes($bare, $refined, request: true))->toBe(['schema.refinement-narrowed!'], $keyword.' arriving on a request')
        ->and(schemaDiffCodes($bare, $refined, request: false))->toBe(['schema.refinement-narrowed!'], $keyword.' arriving on a response')
        ->and(schemaDiffCodes($refined, $bare, request: true))->toBe(['schema.refinement-widened'], $keyword.' leaving a request')
        ->and(schemaDiffCodes($refined, $bare, request: false))->toBe(['schema.refinement-widened!'], $keyword.' leaving a response')
        // And the probe is what caused it — a comparison of a schema with itself says nothing.
        ->and(schemaDiffCodes($refined, $refined, request: true))->toBe([], $keyword.' reports itself changed');
})->with(refinementComparedDataset());

it('classifies every refinement it compares in both directions', function (
    array $old,
    array $new,
    array $onRequest,
    array $onResponse,
): void {
    expect(schemaDiffCodes($old, $new, request: true))->toBe($onRequest, 'request')
        ->and(schemaDiffCodes($old, $new, request: false))->toBe($onResponse, 'response');
})->with([
    // Ceilings: lower is narrower. This is the edit the issue was opened on.
    'maxLength tightened' => [
        ['type' => 'string', 'maxLength' => 100],
        ['type' => 'string', 'maxLength' => 3],
        ['schema.refinement-narrowed!'], ['schema.refinement-narrowed!'],
    ],
    'maxLength relaxed' => [
        ['type' => 'string', 'maxLength' => 3],
        ['type' => 'string', 'maxLength' => 100],
        ['schema.refinement-widened'], ['schema.refinement-widened!'],
    ],
    'maxItems tightened' => [
        ['type' => 'array', 'maxItems' => 10],
        ['type' => 'array', 'maxItems' => 2],
        ['schema.refinement-narrowed!'], ['schema.refinement-narrowed!'],
    ],
    'maxItems relaxed' => [
        ['type' => 'array', 'maxItems' => 2],
        ['type' => 'array', 'maxItems' => 10],
        ['schema.refinement-widened'], ['schema.refinement-widened!'],
    ],
    'maxProperties tightened' => [
        ['type' => 'object', 'maxProperties' => 10],
        ['type' => 'object', 'maxProperties' => 2],
        ['schema.refinement-narrowed!'], ['schema.refinement-narrowed!'],
    ],
    'maxProperties relaxed' => [
        ['type' => 'object', 'maxProperties' => 2],
        ['type' => 'object', 'maxProperties' => 10],
        ['schema.refinement-widened'], ['schema.refinement-widened!'],
    ],
    'maximum lowered' => [
        ['type' => 'integer', 'maximum' => 100],
        ['type' => 'integer', 'maximum' => 10],
        ['schema.refinement-narrowed!'], ['schema.refinement-narrowed!'],
    ],
    'maximum raised' => [
        ['type' => 'integer', 'maximum' => 10],
        ['type' => 'integer', 'maximum' => 100],
        ['schema.refinement-widened'], ['schema.refinement-widened!'],
    ],
    'exclusiveMaximum lowered' => [
        ['type' => 'number', 'exclusiveMaximum' => 100],
        ['type' => 'number', 'exclusiveMaximum' => 10],
        ['schema.refinement-narrowed!'], ['schema.refinement-narrowed!'],
    ],
    'exclusiveMaximum raised' => [
        ['type' => 'number', 'exclusiveMaximum' => 10],
        ['type' => 'number', 'exclusiveMaximum' => 100],
        ['schema.refinement-widened'], ['schema.refinement-widened!'],
    ],
    // Floors: higher is narrower, which is the half a single "smaller is stricter" reading gets
    // backwards — and the reason the direction is the keyword's own rather than the family's.
    'minLength raised' => [
        ['type' => 'string', 'minLength' => 3],
        ['type' => 'string', 'minLength' => 8],
        ['schema.refinement-narrowed!'], ['schema.refinement-narrowed!'],
    ],
    'minLength lowered' => [
        ['type' => 'string', 'minLength' => 8],
        ['type' => 'string', 'minLength' => 3],
        ['schema.refinement-widened'], ['schema.refinement-widened!'],
    ],
    'minItems raised' => [
        ['type' => 'array', 'minItems' => 1],
        ['type' => 'array', 'minItems' => 4],
        ['schema.refinement-narrowed!'], ['schema.refinement-narrowed!'],
    ],
    'minItems lowered' => [
        ['type' => 'array', 'minItems' => 4],
        ['type' => 'array', 'minItems' => 1],
        ['schema.refinement-widened'], ['schema.refinement-widened!'],
    ],
    'minProperties raised' => [
        ['type' => 'object', 'minProperties' => 1],
        ['type' => 'object', 'minProperties' => 4],
        ['schema.refinement-narrowed!'], ['schema.refinement-narrowed!'],
    ],
    'minProperties lowered' => [
        ['type' => 'object', 'minProperties' => 4],
        ['type' => 'object', 'minProperties' => 1],
        ['schema.refinement-widened'], ['schema.refinement-widened!'],
    ],
    'minimum raised' => [
        ['type' => 'integer', 'minimum' => 1],
        ['type' => 'integer', 'minimum' => 5],
        ['schema.refinement-narrowed!'], ['schema.refinement-narrowed!'],
    ],
    'minimum lowered' => [
        ['type' => 'integer', 'minimum' => 5],
        ['type' => 'integer', 'minimum' => 1],
        ['schema.refinement-widened'], ['schema.refinement-widened!'],
    ],
    'exclusiveMinimum raised' => [
        ['type' => 'number', 'exclusiveMinimum' => 1],
        ['type' => 'number', 'exclusiveMinimum' => 5],
        ['schema.refinement-narrowed!'], ['schema.refinement-narrowed!'],
    ],
    'exclusiveMinimum lowered' => [
        ['type' => 'number', 'exclusiveMinimum' => 5],
        ['type' => 'number', 'exclusiveMinimum' => 1],
        ['schema.refinement-widened'], ['schema.refinement-widened!'],
    ],
    // A floor written out at the value its absence already meant moves nothing. Absent is 0 for the
    // three length/size floors, so `minLength: 0` is a restatement — the same argument `minContains`'
    // default of 1 makes one level up, and the reason `absent` is a per-keyword fact.
    'minLength restated at zero' => [
        ['type' => 'string'],
        ['type' => 'string', 'minLength' => 0],
        [], [],
    ],
    'minItems restated at zero' => [
        ['type' => 'array'],
        ['type' => 'array', 'minItems' => 0],
        [], [],
    ],
    'minimum has no such default, so zero is a floor' => [
        ['type' => 'integer'],
        ['type' => 'integer', 'minimum' => 0],
        ['schema.refinement-narrowed!'], ['schema.refinement-narrowed!'],
    ],
    // `multipleOf` is neither larger nor smaller: narrower is "a multiple of what it was".
    'multipleOf refined' => [
        ['type' => 'integer', 'multipleOf' => 2],
        ['type' => 'integer', 'multipleOf' => 4],
        ['schema.refinement-narrowed!'], ['schema.refinement-narrowed!'],
    ],
    'multipleOf coarsened' => [
        ['type' => 'integer', 'multipleOf' => 4],
        ['type' => 'integer', 'multipleOf' => 2],
        ['schema.refinement-widened'], ['schema.refinement-widened!'],
    ],
    'multipleOf swapped for one that divides neither way' => [
        ['type' => 'integer', 'multipleOf' => 2],
        ['type' => 'integer', 'multipleOf' => 3],
        ['schema.refinement-changed!'], ['schema.refinement-changed!'],
    ],
    // A decimal step does not divide exactly in binary — `0.1 / 0.05` is 2.0000000000000004 — so a
    // reader with no tolerance calls an ordinary relaxation a change nothing can order.
    'multipleOf coarsened by a decimal step' => [
        ['type' => 'number', 'multipleOf' => 0.1],
        ['type' => 'number', 'multipleOf' => 0.05],
        ['schema.refinement-widened'], ['schema.refinement-widened!'],
    ],
    'multipleOf refined by a decimal step' => [
        ['type' => 'number', 'multipleOf' => 0.05],
        ['type' => 'number', 'multipleOf' => 0.1],
        ['schema.refinement-narrowed!'], ['schema.refinement-narrowed!'],
    ],
    // Its own domain is the positive numbers; anything else is a value nothing can order.
    'multipleOf at zero' => [
        ['type' => 'integer', 'multipleOf' => 2],
        ['type' => 'integer', 'multipleOf' => 0],
        ['schema.refinement-changed!'], ['schema.refinement-changed!'],
    ],
    // `uniqueItems` is off where nobody wrote it, so turning it on narrows and writing `false` out
    // restates the default.
    'uniqueItems turned on' => [
        ['type' => 'array', 'uniqueItems' => false],
        ['type' => 'array', 'uniqueItems' => true],
        ['schema.refinement-narrowed!'], ['schema.refinement-narrowed!'],
    ],
    'uniqueItems turned off' => [
        ['type' => 'array', 'uniqueItems' => true],
        ['type' => 'array', 'uniqueItems' => false],
        ['schema.refinement-widened'], ['schema.refinement-widened!'],
    ],
    'uniqueItems restated at false' => [
        ['type' => 'array'],
        ['type' => 'array', 'uniqueItems' => false],
        [], [],
    ],
    // The four with no order between two values. A regex containment argument is a real decision
    // procedure nobody should improvise at a release gate, and `const` names one value out of
    // everything — so the change is reported as the change it is, and classed breaking.
    'pattern rewritten' => [
        ['type' => 'string', 'pattern' => '^[a-z]+$'],
        ['type' => 'string', 'pattern' => '^[a-z]{2,8}$'],
        ['schema.refinement-changed!'], ['schema.refinement-changed!'],
    ],
    'const changed' => [
        ['const' => 'draft'],
        ['const' => 'published'],
        ['schema.refinement-changed!'], ['schema.refinement-changed!'],
    ],
    'contentEncoding changed' => [
        ['type' => 'string', 'contentEncoding' => 'base64'],
        ['type' => 'string', 'contentEncoding' => 'base16'],
        ['schema.refinement-changed!'], ['schema.refinement-changed!'],
    ],
    'contentMediaType changed' => [
        ['type' => 'string', 'contentMediaType' => 'application/json'],
        ['type' => 'string', 'contentMediaType' => 'text/csv'],
        ['schema.refinement-changed!'], ['schema.refinement-changed!'],
    ],
    // Two bounds moving are two findings, and one of each is what a reviewer needs to see.
    'a range narrowed at one end and widened at the other' => [
        ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
        ['type' => 'integer', 'minimum' => 5, 'maximum' => 500],
        ['schema.refinement-narrowed!', 'schema.refinement-widened'],
        ['schema.refinement-narrowed!', 'schema.refinement-widened!'],
    ],
]);

it('reads both spellings of exclusivity, and refuses to order the two dialects', function (array $old, array $new, array $expected): void {
    // draft-04 spells exclusivity as a boolean modifier on the `minimum`/`maximum` beside it; 2020-12
    // spells it as the bound itself. A reader that assumes the number silently mis-answers every
    // draft-04 artifact, which is exactly what a diff is handed when `old` is a document from before a
    // dialect migration.
    expect(schemaDiffCodes($old, $new, request: true))->toBe($expected);
})->with([
    'draft-04 exclusivity turned on' => [
        ['type' => 'number', 'minimum' => 1],
        ['type' => 'number', 'minimum' => 1, 'exclusiveMinimum' => true],
        ['schema.refinement-narrowed!'],
    ],
    'draft-04 exclusivity turned off' => [
        ['type' => 'number', 'minimum' => 1, 'exclusiveMinimum' => true],
        ['type' => 'number', 'minimum' => 1, 'exclusiveMinimum' => false],
        ['schema.refinement-widened'],
    ],
    'draft-04 exclusivity written out as false' => [
        ['type' => 'number', 'minimum' => 1],
        ['type' => 'number', 'minimum' => 1, 'exclusiveMinimum' => false],
        [],
    ],
    'draft-04 exclusivity at the other end' => [
        ['type' => 'number', 'maximum' => 9],
        ['type' => 'number', 'maximum' => 9, 'exclusiveMaximum' => true],
        ['schema.refinement-narrowed!'],
    ],
    // The one case that cannot be ordered: telling `exclusiveMinimum: true` from `exclusiveMinimum: 5`
    // means folding the `minimum` beside it, which is a sibling interaction this comparison does not
    // read. So it is reported as the change it is rather than guessed at in either direction.
    'the two dialects crossed' => [
        ['type' => 'number', 'minimum' => 5, 'exclusiveMinimum' => true],
        ['type' => 'number', 'exclusiveMinimum' => 5],
        ['schema.refinement-changed!', 'schema.refinement-widened'],
    ],
    'a boolean at a keyword with no second spelling' => [
        ['type' => 'string', 'maxLength' => 3],
        ['type' => 'string', 'maxLength' => true],
        ['schema.refinement-changed!'],
    ],
]);

it('refuses to order a bound it cannot read as a number', function (mixed $garbage): void {
    // A comparison runs on whatever an artifact holds. A bound nothing can read is not a bound that
    // went away — reporting it as a widening would tell a release gate the contract relaxed when what
    // actually happened is that nobody knows.
    expect(schemaDiffCodes(['type' => 'string', 'maxLength' => 3], ['type' => 'string', 'maxLength' => $garbage], request: true))
        ->toBe(['schema.refinement-changed!'])
        ->and(schemaDiffCodes(['type' => 'array', 'uniqueItems' => true], ['type' => 'array', 'uniqueItems' => $garbage], request: true))
        ->toBe(['schema.refinement-changed!'])
        ->and(schemaDiffCodes(['type' => 'number', 'multipleOf' => 2], ['type' => 'number', 'multipleOf' => $garbage], request: true))
        ->toBe(['schema.refinement-changed!'])
        // …and the same garbage on both sides is not a change at all.
        ->and(schemaDiffCodes(['type' => 'string', 'maxLength' => $garbage], ['type' => 'string', 'maxLength' => $garbage], request: true))
        ->toBe([]);
})->with([
    'a numeric string' => ['10'],
    'a list' => [[1, 2]],
    'null' => [null],
    'not a number' => [NAN],
]);

it('leaves every refinement with a comparison of its own to that comparison', function (): void {
    // Five rows say "read elsewhere" rather than going undecided, and each names where. A second
    // reading beside the first would report one edit twice — and for the `contains` bounds it would
    // report a bound that constrains nothing, since both are inert with no `contains` beside them.
    $elsewhere = array_values(array_filter(
        refinementKeywords(),
        static fn (string $keyword): bool => SchemaRefinement::rule($keyword)['kind'] === RefinementKind::Elsewhere,
    ));

    expect($elsewhere)->toBe(['contentSchema', 'enum', 'format', 'maxContains', 'minContains'])
        // Each still reports, under the code its own comparison publishes and once only.
        ->and(schemaDiffCodes(['type' => 'string', 'enum' => ['a', 'b']], ['type' => 'string', 'enum' => ['a']], request: true))
        ->toBe(['schema.enum-value-removed!'])
        ->and(schemaDiffCodes(['type' => 'string'], ['type' => 'string', 'format' => 'uuid'], request: true))
        ->toBe(['schema.format-changed!'])
        ->and(schemaDiffCodes(
            ['type' => 'array', 'contains' => ['type' => 'string'], 'minContains' => 1],
            ['type' => 'array', 'contains' => ['type' => 'string'], 'minContains' => 2],
            request: true,
        ))->toBe(['schema.contains-bound-narrowed!'])
        // A bound with no `contains` beside it constrains nothing, which is why absorbing the pair into
        // the general comparison would report an edit that moved no contract.
        ->and(schemaDiffCodes(['type' => 'array', 'minContains' => 1], ['type' => 'array', 'minContains' => 4], request: true))
        ->toBe([])
        // `contentSchema` is a subschema, so it goes through the position table like any other.
        ->and(schemaDiffCodes(
            ['type' => 'string', 'contentSchema' => ['type' => ['string', 'integer']]],
            ['type' => 'string', 'contentSchema' => ['type' => 'string']],
            request: true,
        ))->toBe(['schema.type-narrowed!']);
});

it('reports no refinement for a keyword that is not one', function (): void {
    // The filter, executed: the comparison walks every keyword either side carries, so a shape or an
    // annotation reaching it would be reported as a bound that moved.
    expect(schemaDiffCodes(['type' => 'string', 'title' => 'Was'], ['type' => 'string', 'title' => 'Now'], request: true))
        ->toBe(['schema.annotation-changed'])
        ->and(schemaDiffCodes(['type' => 'string'], ['type' => 'integer'], request: true))
        ->toBe(['schema.type-changed!'])
        ->and(schemaDiffCodes(['type' => 'object', 'required' => []], ['type' => 'object', 'required' => ['a']], request: true))
        ->toBe(['schema.required-added!']);
});

it('carries a refinement change up from every subschema position it sits under', function (): void {
    // The narrowing the composition work made visible at the position and this one makes visible at the
    // keyword: `propertyNames: {type: string}` gaining a `maxLength: 3` used to report nothing at all.
    expect(schemaDiffCodes(
        ['propertyNames' => ['type' => 'string']],
        ['propertyNames' => ['type' => 'string', 'maxLength' => 3]],
        request: true,
    ))->toBe(['schema.refinement-narrowed!'])
        ->and(schemaDiffCodes(
            ['type' => 'object', 'properties' => ['name' => ['type' => 'string', 'maxLength' => 255]]],
            ['type' => 'object', 'properties' => ['name' => ['type' => 'string', 'maxLength' => 10]]],
            request: true,
        ))->toBe(['schema.refinement-narrowed!'])
        ->and(schemaDiffCodes(
            ['allOf' => [['type' => 'string', 'maxLength' => 255]]],
            ['allOf' => [['type' => 'string', 'maxLength' => 10]]],
            request: true,
        ))->toBe(['schema.refinement-narrowed!'])
        // Under `not` the direction inverts, so the child's verdict cannot carry up: a bound RELAXED
        // there narrows the schema carrying it, and the conservative verdict is what says so.
        ->and(schemaDiffCodes(
            ['not' => ['type' => 'string', 'maxLength' => 10]],
            ['not' => ['type' => 'string', 'maxLength' => 255]],
            request: true,
        ))->toBe(['schema.refinement-widened!']);
});

it('names a tightened bound through the path a diff actually runs', function (): void {
    // The end-to-end claim: this is the edit `--enforce` used to pass as safe. A request body that took
    // a 255-character title now takes ten, and the gate says so, at the path a reviewer can find.
    $document = static fn (int $cap): UirDocument => UirDocument::fromArray([
        'uir' => '1.0.0',
        'openapi' => '3.2.0',
        'info' => ['title' => 'API', 'version' => '1.0.0'],
        'paths' => ['/things' => ['post' => [
            'x-docuccino' => ['id' => 'op:v1:aaaaaaaaaaaaaaaa'],
            'operationId' => 'things.store',
            'requestBody' => ['content' => ['application/json' => ['schema' => [
                'type' => 'object',
                'properties' => ['title' => ['type' => 'string', 'maxLength' => $cap]],
            ]]]],
            'responses' => ['201' => [
                'x-docuccino' => ['id' => 'res:v1:bbbbbbbbbbbbbbbb'],
                'description' => 'created',
            ]],
        ]]],
    ]);

    $changeset = (new DocumentDiffer)->diff($document(255), $document(10));
    $found = array_values(array_filter(
        $changeset->changes,
        static fn ($change): bool => $change->code === 'schema.refinement-narrowed',
    ));

    expect($changeset->isBreaking())->toBeTrue()
        ->and($found)->toHaveCount(1)
        ->and($found[0]->path)->toBe('POST /things requestBody application/json schema.properties.title.maxLength')
        ->and($found[0]->fields[0]->field)->toBe('maxLength')
        ->and($found[0]->fields[0]->old)->toBe(255)
        ->and($found[0]->fields[0]->new)->toBe(10);
});
