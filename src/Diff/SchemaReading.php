<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff;

use Docuccino\Core\Draft\SchemaKeywords;
use Docuccino\Core\Support\Arr;
use Docuccino\Core\Support\Hydrate;

/**
 * One recorded decision per keyword that carries no subschema and refines no value — the third of the
 * three sets a schema diff owes an answer for, and the one neither sibling could see: {@see SchemaPolarity}
 * is keyed to the subschema positions and {@see SchemaRefinement} to the refinements, so `discriminator`,
 * `nullable`, `$id`, `$anchor` and `$schema` sat in the gap between two derived guards and were read by
 * nothing. {@see SchemaComparator} is the only reader. The kinds are in
 * docs/design/uir-and-extensions.md §1 "Diff polarity".
 *
 * The table is the whole remainder rather than the five that were unread, because a decision recorded as
 * "read elsewhere" or "read by nothing, for this reason" is what stops a sixth member hiding in the same
 * gap: the three sets together are held against every keyword the draft model knows.
 *
 * @phpstan-type Move array{move: RefinementMove, old: mixed, new: mixed}
 *
 * @internal
 */
final class SchemaReading
{
    /**
     * The decision for every keyword that is neither a subschema position nor a refinement, keyword =>
     * reading. What keeps it from going stale is the derived guard in `SchemaReadingDiffTest`, which reads
     * {@see decided()} against that remainder in both directions — and, beside it, the union of all three
     * tables against {@see SchemaKeywords::classification()}, because two guards each keyed to their own
     * subset never added up to coverage.
     *
     * @var array<string, ReadingKind>
     */
    private const array RULES = [
        // The two the release gate was silently missing. A discriminator tells a client which subschema
        // to deserialise a payload as, so a repointed mapping entry mis-types an object that still
        // validates; `nullable` is 3.0's spelling of a type union's `null` branch.
        'discriminator' => ReadingKind::Discriminator,
        'nullable' => ReadingKind::Nullability,
        // A name a `$ref` may resolve, and the dialect the keywords beside it are written in. `$id` gets
        // a row of its own because it is not only a name: it re-bases every `$ref` BENEATH it, so the
        // two members differ on what one ARRIVING is worth and one row answering for both was a sentence
        // true of `$anchor` and false of `$id`.
        '$id' => ReadingKind::Base,
        '$anchor' => ReadingKind::Identity,
        '$schema' => ReadingKind::Dialect,
        // Each already a member of the diff's own vocabulary, and each named where it is read:
        // `compareRef()`, `compareType()`, `compareRequired()`.
        '$ref' => ReadingKind::Elsewhere,
        'type' => ReadingKind::Elsewhere,
        'required' => ReadingKind::Elsewhere,
        // The annotation-only set, read by `compareAnnotations()` as the non-events they are. Which
        // keywords those are is {@see SchemaKeywords::isAnnotationOnly()}'s answer, and the guard holds
        // these rows to it rather than letting two lists drift apart.
        '$comment' => ReadingKind::Annotation,
        'description' => ReadingKind::Annotation,
        'example' => ReadingKind::Annotation,
        'examples' => ReadingKind::Annotation,
        'externalDocs' => ReadingKind::Annotation,
        'title' => ReadingKind::Annotation,
        // Read by nothing, each for a reason of its own. `x-docuccino` carries the identity the diff
        // pairs nodes BY, so comparing it would report the pairing rather than the contract. The other
        // four say what the server fills in, whether a value may be sent, whether it comes back and
        // whether it is being withdrawn — each a contract claim this diff does not yet read, recorded
        // here as the gap it is rather than left where nobody can see it.
        'x-docuccino' => ReadingKind::Unread,
        'default' => ReadingKind::Unread,
        'readOnly' => ReadingKind::Unread,
        'writeOnly' => ReadingKind::Unread,
        'deprecated' => ReadingKind::Unread,
    ];

    /**
     * The reading for `$keyword`. A keyword nobody has decided is {@see ReadingKind::Undecided} —
     * reported, and classed breaking — because silence is the one answer a release gate cannot recover
     * from. A keyword arrives from a document, so the fallback is a runtime one; a {@see ReadingKind}
     * never does, which is why that axis is decided at analysis time instead.
     */
    public static function rule(string $keyword): ReadingKind
    {
        return self::RULES[$keyword] ?? ReadingKind::Undecided;
    }

    /**
     * How the diff reads `$keyword`, or null where this table is not the one that decides it: a keyword
     * the draft model does not know at all is data the document carries rather than a contract term, and
     * one carrying a subschema or refining an instance is a sibling table's decision. That is the filter
     * a caller walking a schema's keywords wants; everything else here is a decision somebody made.
     */
    public static function kindOf(string $keyword): ?ReadingKind
    {
        if (! SchemaKeywords::knows($keyword)) {
            return null;
        }

        if (SchemaKeywords::positionOf($keyword) !== null || SchemaKeywords::isRefinement($keyword)) {
            return null;
        }

        return self::rule($keyword);
    }

    /**
     * Every keyword a decision has been recorded for, so the guard reads this set rather than a second
     * copy of it. The set it is checked against is what {@see SchemaKeywords::classification()} knows
     * minus the two sibling tables' keywords, in both directions — a keyword the draft model learns and
     * a keyword only this table knows are both a decision nobody made.
     *
     * @return list<string>
     */
    public static function decided(): array
    {
        return array_keys(self::RULES);
    }

    /**
     * Whether `nullable` says the value admits null where the sides disagree — and it is read beside the
     * TYPE union, because the two are one statement in two dialects: `{type: string, nullable: true}`
     * becoming `{type: [string, null]}` moves no contract, and a reading of the keyword alone would call
     * that migration a narrowing and fail the gate on it. The type answers are handed in rather than read
     * here, the way `contains`' own bounds are, because the type set is the comparator's to read.
     *
     * Absent is not nullable, so the keyword arriving with `true` widens and it leaving narrows. A value
     * that is no boolean is a change nothing can order rather than a null admitted or withdrawn.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    public static function nullability(array $old, array $new, bool $wasTypedNull, bool $isTypedNull): RefinementMove
    {
        $before = $old['nullable'] ?? null;
        $after = $new['nullable'] ?? null;

        if (ValueKey::of($before) === ValueKey::of($after)) {
            return RefinementMove::Unchanged;
        }

        if (($before !== null && ! is_bool($before)) || ($after !== null && ! is_bool($after))) {
            return RefinementMove::Incomparable;
        }

        $was = $before === true || $wasTypedNull;
        $is = $after === true || $isTypedNull;

        if ($was === $is) {
            return RefinementMove::Unchanged;
        }

        return $is ? RefinementMove::Widened : RefinementMove::Narrowed;
    }

    /**
     * Which of a Discriminator Object's members moved, member => move, in one sorted pass so the answer
     * never depends on which side declared what. A `mapping` is a MAP — the tag value is the contract, not
     * the slot it sits in — so its entries pair by KEY and a reordered mapping is not a change; every other
     * member compares as a value, which is why a member OpenAPI adds later is read the day it appears
     * rather than joining the gap this table exists to close.
     *
     * Only `mapping` has a direction: an entry removed narrows what a payload may be tagged, one added
     * widens it, and one REPOINTED is neither — it routes a tag a client is already sending to a different
     * type, which is the mis-typed object this comparison exists for. `propertyName` has no widening
     * reading at all: the tag moves to another property and every client reads the wrong field.
     *
     * @return array<string, Move>
     */
    public static function discriminatorMoves(mixed $old, mixed $new): array
    {
        $was = Hydrate::mapOrNull($old) ?? [];
        $is = Hydrate::mapOrNull($new) ?? [];
        $moves = [];

        foreach (Arr::sortedUnion(array_keys($was), array_keys($is)) as $member) {
            if ($member === 'mapping') {
                foreach (self::mappingMoves($was[$member] ?? null, $is[$member] ?? null) as $tag => $move) {
                    $moves[$member.'.'.$tag] = $move;
                }

                continue;
            }

            $before = $was[$member] ?? null;
            $after = $is[$member] ?? null;

            if (ValueKey::of($before) !== ValueKey::of($after)) {
                $moves[$member] = ['move' => RefinementMove::Incomparable, 'old' => $before, 'new' => $after];
            }
        }

        return $moves;
    }

    /**
     * One `mapping`'s entries, tag => move.
     *
     * @return array<string, Move>
     */
    private static function mappingMoves(mixed $old, mixed $new): array
    {
        $was = Hydrate::mapOrNull($old) ?? [];
        $is = Hydrate::mapOrNull($new) ?? [];
        $moves = [];

        foreach (Arr::sortedUnion(array_keys($was), array_keys($is)) as $tag) {
            $had = array_key_exists($tag, $was);
            $has = array_key_exists($tag, $is);
            $before = $was[$tag] ?? null;
            $after = $is[$tag] ?? null;

            if ($had && $has) {
                if (ValueKey::of($before) !== ValueKey::of($after)) {
                    $moves[$tag] = ['move' => RefinementMove::Incomparable, 'old' => $before, 'new' => $after];
                }

                continue;
            }

            $moves[$tag] = [
                'move' => $has ? RefinementMove::Widened : RefinementMove::Narrowed,
                'old' => $before,
                'new' => $after,
            ];
        }

        return $moves;
    }
}
