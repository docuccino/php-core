<?php

declare(strict_types=1);

use Docuccino\Core\Diff\ReadingKind;
use Docuccino\Core\Diff\RefinementKind;
use Docuccino\Core\Diff\RefinementMove;
use Docuccino\Core\Diff\SchemaComparator;
use Docuccino\Core\Diff\SchemaMember;
use Docuccino\Core\Diff\SchemaPolarity;
use Docuccino\Core\Diff\SchemaReading;
use Docuccino\Core\Diff\SchemaRefinement;
use Docuccino\Core\Draft\SchemaKeywords;

/**
 * The verdict layer, which is the one all three decision tables share and the one nothing held together.
 * Each table answers in DIRECTIONS — narrowed, widened, indeterminate — and every direction is turned
 * into `(breaking, code suffix)` by one rule: narrowed and indeterminate gate both sides, widened gates
 * a RESPONSE alone, because a writer stays valid while a reader meets a value it has no case for.
 *
 * That rule was implemented in eight places and four of them disagreed, so `maxItems: 2 → 9` failed
 * `--enforce` on a response while `maxContains: 2 → 9` passed it — same document, same gate, same day.
 * The sibling guards partition the KEYWORDS; this one partitions the VERDICTS: every path that computes
 * a direction is driven through the real comparator, and the change it publishes must carry the verdict
 * that direction earns. The four corrected paths are what it catches today; its job is the fifth.
 *
 * Three readings deliberately never reach here, each stated where it is made: `required` and `format`
 * are obligations on a writer, `properties` members are fields a consumer reads rather than constraints,
 * and `$id`/`$anchor`/`$schema` have no computed direction at all.
 */

/**
 * What one direction is worth, stated here rather than read off the comparator: a guard that asked the
 * code for its own rule would agree with whatever the code did.
 */
function verdictGates(RefinementMove $move, bool $request): bool
{
    return match ($move) {
        RefinementMove::Narrowed, RefinementMove::Incomparable => true,
        RefinementMove::Widened => ! $request,
        RefinementMove::Unchanged => false,
    };
}

/**
 * One probe: two schemas, and the direction each table says the edit between them made. `onRequest` and
 * `onResponse` differ for exactly one member kind — `dependentRequired`, whose entries are an obligation
 * on a writer — so the pair is carried rather than one move assumed to serve both.
 *
 * @return array<string, mixed>
 */
function verdictProbe(string $table, string $keyword, array $old, array $new, RefinementMove $onRequest, ?RefinementMove $onResponse = null): array
{
    return [
        'table' => $table,
        'keyword' => $keyword,
        'old' => $old,
        'new' => $new,
        'onRequest' => $onRequest,
        'onResponse' => $onResponse ?? $onRequest,
    ];
}

/**
 * The value edits a refinement of each kind can make, so the corpus is derived from the kind a keyword's
 * row records rather than from a list of keywords somebody kept up to date. `null` means the keyword is
 * absent on that side — which is a real edit for every kind here, and the one that makes `minLength: 0`
 * a restatement rather than a floor arriving.
 *
 * @return array<string, array{mixed, mixed}>
 */
function verdictRefinementEdits(RefinementKind $kind): array
{
    return match ($kind) {
        RefinementKind::UpperBound, RefinementKind::LowerBound => [
            'arriving' => [null, 5],
            'leaving' => [5, null],
            'raised' => [5, 7],
            'lowered' => [7, 5],
            'unreadable' => ['nonsense', 5],
        ],
        RefinementKind::Divisor => [
            'arriving' => [null, 4],
            'leaving' => [4, null],
            'to a multiple' => [2, 4],
            'to a factor' => [4, 2],
            'to neither' => [2, 3],
        ],
        RefinementKind::Flag => [
            'arriving' => [null, true],
            'leaving' => [true, null],
            'switched off' => [true, false],
            'unreadable' => ['nonsense', true],
        ],
        RefinementKind::Opaque => [
            'arriving' => [null, 'a'],
            'leaving' => ['a', null],
            'rewritten' => ['a', 'b'],
        ],
        // A refinement read by a comparison of its own contributes no direction here, and one nobody has
        // decided cannot be probed by value — it is the fallback, not a row.
        RefinementKind::Elsewhere, RefinementKind::Undecided => [],
    };
}

/**
 * Every path in the three tables that computes a direction, as probes the real comparator can run. The
 * SETS are read off the tables — `SchemaRefinement::decided()` and `SchemaPolarity::decided()` — so a
 * keyword added to either is probed without being named here, and `verdictProbeCoverage()` fails when a
 * table grows a path this corpus does not reach.
 *
 * @return array<string, array<string, mixed>>
 */
function verdictProbes(): array
{
    $probes = [];

    // ---- Refinements: one direction per keyword, in the keyword's own value space.
    foreach (SchemaRefinement::decided() as $keyword) {
        $rule = SchemaRefinement::rule($keyword);
        $edits = verdictRefinementEdits($rule['kind']);

        // draft-04 spells exclusivity as a boolean modifier and 2020-12 as the bound itself; a boolean
        // against a number is the one bound comparison nothing can order.
        if ($rule['draft04Boolean']) {
            $edits['across dialects'] = [true, 5];
        }

        foreach ($edits as $label => [$before, $after]) {
            $old = ['type' => 'string'] + ($before === null ? [] : [$keyword => $before]);
            $new = ['type' => 'string'] + ($after === null ? [] : [$keyword => $after]);
            $move = SchemaRefinement::move($keyword, $old, $new);

            if ($move === RefinementMove::Unchanged) {
                continue;
            }

            $probes['refinement · '.$keyword.' · '.$label] = verdictProbe('refinement', $keyword, $old, $new, $move);
        }
    }

    // ---- Positions: the KEYWORD arriving or leaving, where the side without it carried no constraint
    // of that kind at all. Only the positions whose absence is a claim of its own reach that question.
    foreach (SchemaPolarity::decided() as $keyword) {
        $rule = SchemaPolarity::rule($keyword);
        $position = SchemaKeywords::positionOf($keyword);

        if ($rule['member'] === SchemaMember::EmptySchema) {
            continue;
        }

        if ($position === SchemaKeywords::POSITION_SCHEMA || $position === SchemaKeywords::POSITION_SCHEMA_LIST) {
            $inner = $position === SchemaKeywords::POSITION_SCHEMA_LIST
                ? [['$ref' => '#/components/schemas/A']]
                : ['type' => 'string'];

            foreach (['arriving' => true, 'leaving' => false] as $label => $arriving) {
                $bare = ['type' => 'object'];
                $full = ['type' => 'object', $keyword => $inner];
                [$old, $new] = $arriving ? [$bare, $full] : [$full, $bare];
                $move = SchemaPolarity::keywordPresence($rule['member'], $arriving, SchemaKeywords::containsAsserts($arriving ? $new : $old));

                $probes['keyword · '.$keyword.' · '.$label] = verdictProbe('keyword', $keyword, $old, $new, $move);
            }

            // …and the one position whose arrival may assert nothing at all, which is a direction of its
            // own rather than a verdict of its own.
            if ($rule['member'] === SchemaMember::Bounded) {
                $bare = ['type' => 'object'];
                $inert = ['type' => 'object', $keyword => $inner, 'minContains' => 0];

                $probes['keyword · '.$keyword.' · arriving inert'] = verdictProbe(
                    'keyword',
                    $keyword,
                    $bare,
                    $inert,
                    SchemaPolarity::keywordPresence($rule['member'], true, SchemaKeywords::containsAsserts($inert)),
                );
            }
        }

        // ---- Positions: ONE MEMBER arriving or leaving, where the position stood on both sides.
        $members = match (true) {
            $position === SchemaKeywords::POSITION_SCHEMA_LIST => [
                'one' => [$keyword => [['$ref' => '#/components/schemas/A']]],
                'two' => [$keyword => [['$ref' => '#/components/schemas/A'], ['$ref' => '#/components/schemas/B']]],
            ],
            $position === SchemaKeywords::POSITION_SCHEMA_MAP && $rule['member'] === SchemaMember::Store => [
                'one' => [$keyword => ['A' => ['type' => 'string']]],
                'two' => [$keyword => ['A' => ['type' => 'string'], 'B' => ['type' => 'integer']]],
            ],
            $position === SchemaKeywords::POSITION_STRING_LIST_MAP => [
                'one' => [$keyword => ['a' => ['b']]],
                'two' => [$keyword => ['a' => ['b', 'c']]],
            ],
            default => [],
        };

        if ($members === []) {
            continue;
        }

        foreach (['arriving' => true, 'leaving' => false] as $label => $arriving) {
            [$old, $new] = $arriving
                ? [['type' => 'object'] + $members['one'], ['type' => 'object'] + $members['two']]
                : [['type' => 'object'] + $members['two'], ['type' => 'object'] + $members['one']];

            $probes['member · '.$keyword.' · '.$label] = verdictProbe(
                'member',
                $keyword,
                $old,
                $new,
                SchemaPolarity::memberPresence($rule['member'], $arriving, true),
                SchemaPolarity::memberPresence($rule['member'], $arriving, false),
            );
        }
    }

    // ---- Readings: `nullable` beside the type union it is the other dialect of, and the Discriminator
    // Object's own members. Both directions are read off the table rather than restated.
    $nullable = [
        'admitted' => [['type' => 'string'], ['type' => 'string', 'nullable' => true]],
        'withdrawn' => [['type' => 'string', 'nullable' => true], ['type' => 'string']],
        'unreadable' => [['type' => 'string'], ['type' => 'string', 'nullable' => 'yes']],
    ];

    foreach ($nullable as $label => [$old, $new]) {
        $probes['reading · nullable · '.$label] = verdictProbe(
            'reading',
            'nullable',
            $old,
            $new,
            SchemaReading::nullability($old, $new, false, false),
        );
    }

    $union = [['$ref' => '#/components/schemas/Invoice'], ['$ref' => '#/components/schemas/Subscription']];
    $discriminated = static fn (array $object): array => ['oneOf' => $union, 'discriminator' => $object];
    $mapping = ['invoice' => '#/components/schemas/Invoice'];

    $discriminator = [
        'a mapping entry added' => [
            $discriminated(['propertyName' => 'type', 'mapping' => $mapping]),
            $discriminated(['propertyName' => 'type', 'mapping' => $mapping + ['subscription' => '#/components/schemas/Subscription']]),
        ],
        'a mapping entry removed' => [
            $discriminated(['propertyName' => 'type', 'mapping' => $mapping + ['subscription' => '#/components/schemas/Subscription']]),
            $discriminated(['propertyName' => 'type', 'mapping' => $mapping]),
        ],
        'a mapping entry repointed' => [
            $discriminated(['propertyName' => 'type', 'mapping' => $mapping]),
            $discriminated(['propertyName' => 'type', 'mapping' => ['invoice' => '#/components/schemas/Subscription']]),
        ],
        'the property rewritten' => [
            $discriminated(['propertyName' => 'type', 'mapping' => $mapping]),
            $discriminated(['propertyName' => 'kind', 'mapping' => $mapping]),
        ],
    ];

    foreach ($discriminator as $label => [$old, $new]) {
        $moves = SchemaReading::discriminatorMoves($old['discriminator'], $new['discriminator']);

        $probes['reading · discriminator · '.$label] = verdictProbe(
            'reading',
            'discriminator',
            $old,
            $new,
            reset($moves)['move'],
        );
    }

    // ---- The comparisons this class runs itself, whose direction is a fact about a schema rather than
    // a row in a table — so nothing derives them and each is stated. They earn no verdict rule of their
    // own either, which is the whole point of listing them here: `verdictOwnComparisons()` holds this
    // set against `SchemaReading`'s own record of which keywords have a comparison of their own.
    $bounded = static fn (array $extra): array => ['contains' => ['type' => 'string']] + $extra;

    $own = [
        ['minContains', 'raised', $bounded(['minContains' => 1]), $bounded(['minContains' => 2]), RefinementMove::Narrowed],
        ['minContains', 'lowered', $bounded(['minContains' => 2]), $bounded(['minContains' => 1]), RefinementMove::Widened],
        ['maxContains', 'capped', $bounded([]), $bounded(['maxContains' => 9]), RefinementMove::Narrowed],
        ['maxContains', 'raised', $bounded(['maxContains' => 2]), $bounded(['maxContains' => 9]), RefinementMove::Widened],
        ['enum', 'the constraint dropped', ['type' => 'string', 'enum' => ['a', 'b']], ['type' => 'string'], RefinementMove::Widened],
        ['enum', 'a value added', ['type' => 'string', 'enum' => ['a', 'b']], ['type' => 'string', 'enum' => ['a', 'b', 'c']], RefinementMove::Widened],
        // `type` reads the type SETS as the lattice they are, so all five of its codes are three
        // directions between them. It was the last comparison here still classing a widening safe on a
        // response, which is why every one of its five is probed rather than the two that moved.
        ['type', 'arriving where the value was untyped', [], ['type' => 'string'], RefinementMove::Narrowed],
        ['type', 'the constraint leaving', ['type' => 'string'], [], RefinementMove::Widened],
        ['type', 'the set grown', ['type' => 'string'], ['type' => ['string', 'integer']], RefinementMove::Widened],
        ['type', 'the set shrunk', ['type' => ['string', 'integer']], ['type' => 'string'], RefinementMove::Narrowed],
        ['type', 'replaced by one neither contains', ['type' => 'string'], ['type' => 'integer'], RefinementMove::Incomparable],
    ];

    foreach ($own as [$keyword, $label, $old, $new, $move]) {
        $probes['own · '.$keyword.' · '.$label] = verdictProbe('own', $keyword, $old, $new, $move);
    }

    // The Discriminator Object's own presence, which is neither a member move nor a comparison of its
    // own: the keyword arriving makes a client tag what it could send untagged, and it leaving takes the
    // tag a response reader was switching on.
    $tagged = ['oneOf' => $union, 'discriminator' => ['propertyName' => 'type']];
    $probes['reading · discriminator · the keyword arriving'] = verdictProbe('reading', 'discriminator', ['oneOf' => $union], $tagged, RefinementMove::Narrowed);
    $probes['reading · discriminator · the keyword leaving'] = verdictProbe('reading', 'discriminator', $tagged, ['oneOf' => $union], RefinementMove::Widened);

    return $probes;
}

/**
 * The keywords `SchemaReading` records as having a comparison of THEIR OWN, which is the set nothing
 * derives probes for: their direction is computed in the comparator rather than looked up. Each is
 * therefore either probed above or excused here by name, so a keyword moved to that reading cannot
 * quietly acquire a verdict rule nobody checks — which is how `type` came to keep one.
 *
 * @return array{list<string>, array<string, string>}
 */
function verdictOwnComparisons(): array
{
    $own = [
        ...array_filter(
            SchemaReading::decided(),
            static fn (string $keyword): bool => SchemaReading::rule($keyword) === ReadingKind::Elsewhere,
        ),
        ...array_filter(
            SchemaRefinement::decided(),
            static fn (string $keyword): bool => SchemaRefinement::rule($keyword)['kind'] === RefinementKind::Elsewhere,
        ),
    ];

    return [array_values($own), [
        // Compared opaquely, by target string: the component a `$ref` names carries its own diff, so
        // there is no direction here for a verdict to be applied to.
        '$ref' => 'compares opaquely by target string',
        // Obligations on a WRITER, so each gates a request and is a report on a response — the two
        // documented exceptions to the shared rule rather than paths that compute a direction.
        'required' => 'is an obligation on a writer, not a direction',
        'format' => 'is an obligation on a writer, not a direction',
        // A subschema, so its direction is the position table's and the probes above already reach it
        // through `SchemaPolarity`.
        'contentSchema' => 'is a subschema, read through the position table',
    ]];
}

/** @return array<string, array{array<string, mixed>}> */
function verdictProbeDataset(): array
{
    return array_map(static fn (array $probe): array => [$probe], verdictProbes());
}

it('gives one direction one verdict, wherever in the three tables it was computed', function (array $probe): void {
    // The whole point: the direction comes from the table, the verdict comes from the real comparator,
    // and a path that applies its own rule to a direction the table computed fails here by name.
    foreach (['request' => true, 'response' => false] as $side => $request) {
        $changes = (new SchemaComparator)->compare($probe['old'], $probe['new'], 'S', 'sch:v1:0000000000000000', $request);
        $move = $request ? $probe['onRequest'] : $probe['onResponse'];

        // One probe, one finding: a second change would mean the assertion below was reading an edit the
        // probe did not intend, which is how a guard passes while proving nothing.
        expect($changes)->toHaveCount(1, $probe['keyword'].' on a '.$side.' reports '.count($changes).' changes')
            ->and($changes[0]->breaking)->toBe(
                verdictGates($move, $request),
                $probe['keyword'].' · '.$move->value.' · '.$side.' · '.$changes[0]->code,
            );
    }
})->with(verdictProbeDataset());

it('reaches every path that computes a direction, and every direction there is', function (): void {
    // A corpus proves only what it covers, so the sets are read off the tables and this fails short when
    // one grows a path nothing probes.
    $probes = verdictProbes();
    $covered = static fn (string $table): array => array_values(array_unique(array_map(
        static fn (array $probe): string => $probe['keyword'],
        array_filter($probes, static fn (array $probe): bool => $probe['table'] === $table),
    )));

    $refinements = array_values(array_filter(
        SchemaRefinement::decided(),
        static fn (string $keyword): bool => verdictRefinementEdits(SchemaRefinement::rule($keyword)['kind']) !== [],
    ));

    // Every position whose presence is a claim, minus the two kinds that make none here: an EMPTY
    // position's presence falls out of the ordinary comparison, and `properties` is the documented
    // exception — a member there is a field a consumer reads rather than a constraint on one, so it
    // publishes verdicts of its own and hands this rule no direction.
    $positions = array_values(array_filter(
        SchemaPolarity::decided(),
        static fn (string $keyword): bool => ! in_array(
            SchemaPolarity::rule($keyword)['member'],
            [SchemaMember::EmptySchema, SchemaMember::Property],
            true,
        ),
    ));

    $sorted = static function (array $values): array {
        sort($values);

        return $values;
    };

    // The keywords with a comparison of their own are the ones no table derives, so they are held
    // against `SchemaReading`'s own record: probed here, or excused by name with the reason it hands the
    // rule no direction. `type` was neither for the life of this corpus, which is exactly the hole a
    // fix the verdict guard cannot see leaves behind.
    [$ownComparisons, $excused] = verdictOwnComparisons();

    expect($sorted([...$covered('own'), ...array_keys($excused)]))->toBe($sorted($ownComparisons), 'every comparison of its own')
        ->and($covered('own'))->toContain('type', 'enum', 'minContains', 'maxContains')
        ->and($sorted($covered('refinement')))->toBe($sorted($refinements), 'every refinement that compares by value')
        // Every position whose presence is a claim is probed at the keyword, at a member, or at both —
        // which of the two is the position's own business and not this corpus's.
        ->and($sorted(array_unique([...$covered('keyword'), ...$covered('member')])))->toBe($sorted($positions), 'every position whose presence is a claim')
        // A scan that matches nothing must fail rather than pass forever.
        ->and(count($probes))->toBeGreaterThanOrEqual(80)
        ->and($refinements)->toContain('maxItems', 'minLength', 'multipleOf', 'uniqueItems', 'pattern')
        ->and($positions)->toContain('allOf', 'anyOf', 'oneOf', 'not', 'contains', '$defs', 'dependentRequired');

    // Every direction is exercised, or the rule is only half asserted — an all-`Narrowed` corpus would
    // agree with a verdict function that ignored its argument entirely.
    $seen = [];

    foreach ($probes as $probe) {
        $seen[$probe['onRequest']->value] = true;
        $seen[$probe['onResponse']->value] = true;
    }

    $listed = array_keys($seen);
    sort($listed);

    expect($listed)->toBe(['incomparable', 'narrowed', 'unchanged', 'widened']);
});

it('refuses a path that reads a direction its own way', function (): void {
    // The guard above EXECUTED rather than asserted, at the two rules a drifting path actually breaks.
    // A path that dropped the audience — treating widened as safe on both sides, which is what
    // `containsBound()` did — disagrees with the corpus on every widening it publishes on a response;
    // one that read indeterminate as safe disagrees on every unordered change. Both are spelled out and
    // both must be refused, so the assertion is a rule the corpus can actually break rather than a claim.
    $ignoresAudience = static fn (RefinementMove $move, bool $request): bool => $move !== RefinementMove::Widened;
    $trustsTheUnordered = static fn (RefinementMove $move, bool $request): bool => $move === RefinementMove::Narrowed;

    $disagreements = static function (callable $rule) use (&$disagreements): int {
        $count = 0;

        foreach (verdictProbes() as $probe) {
            foreach ([true, false] as $request) {
                $move = $request ? $probe['onRequest'] : $probe['onResponse'];

                if ($rule($move, $request) !== verdictGates($move, $request)) {
                    $count++;
                }
            }
        }

        return $count;
    };

    expect($disagreements($ignoresAudience))->toBeGreaterThan(0, 'a rule that ignores the audience is indistinguishable')
        ->and($disagreements($trustsTheUnordered))->toBeGreaterThan(0, 'a rule that trusts the unordered is indistinguishable')
        // …and the rule the code actually applies agrees with the corpus everywhere, which is what the
        // dataset above proves change by change.
        ->and($disagreements(verdictGates(...)))->toBe(0);
});
