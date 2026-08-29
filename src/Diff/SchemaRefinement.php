<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff;

use Docuccino\Core\Draft\SchemaKeywords;

/**
 * One recorded decision per REFINEMENT keyword: how two of its values are compared, and so which way an
 * edit moves the schema carrying it. {@see SchemaComparator} is the only reader. The sibling of
 * {@see SchemaPolarity} and a different question — a refinement carries no subschema, so there is no
 * polarity to invert and no member list to pair; what a `maxLength` of 3 is worth beside a `maxLength`
 * of 100 is a direction in the keyword's own value space. The kinds, and the draft-04 split at
 * `exclusiveMinimum`, are in docs/design/uir-and-extensions.md §1 "Diff polarity".
 *
 * `absent` is what the keyword means where it is not written — `0` for the three length/size floors,
 * `null` where absence is no bound at all — and only the two bound kinds read it. It is what makes
 * `minLength: 0` arriving a restatement rather than a floor.
 *
 * @phpstan-type Rule array{kind: RefinementKind, absent: float|null, draft04Boolean: bool}
 *
 * @internal
 */
final class SchemaRefinement
{
    /**
     * The decision for every refinement keyword the draft model knows, keyword => rule. Kept keyed by
     * keyword rather than derived from one of them, because the direction is the keyword's own: `maximum`
     * and `minimum` sit at the same position in a schema and point opposite ways, exactly as `items` and
     * `not` do one level up.
     *
     * What keeps it from going stale is the derived guard in `SchemaRefinementDiffTest`, which reads
     * {@see decided()} against {@see SchemaKeywords::refinements()} in both directions. A refinement the
     * draft model learns and nobody decides is read {@see RefinementKind::Undecided} — reported, and
     * classed breaking — rather than passing as safe.
     *
     * @var array<string, Rule>
     */
    private const array RULES = [
        // Ceilings. Nothing caps a value by default, so any cap arriving narrows however high it is set.
        'maxLength' => ['kind' => RefinementKind::UpperBound, 'absent' => null, 'draft04Boolean' => false],
        'maxItems' => ['kind' => RefinementKind::UpperBound, 'absent' => null, 'draft04Boolean' => false],
        'maxProperties' => ['kind' => RefinementKind::UpperBound, 'absent' => null, 'draft04Boolean' => false],
        'maximum' => ['kind' => RefinementKind::UpperBound, 'absent' => null, 'draft04Boolean' => false],
        'exclusiveMaximum' => ['kind' => RefinementKind::UpperBound, 'absent' => null, 'draft04Boolean' => true],
        // Floors. Three of them have one the keyword restates rather than moves: a string is never
        // shorter than no characters, and an array or an object never holds fewer than no members.
        'minLength' => ['kind' => RefinementKind::LowerBound, 'absent' => 0.0, 'draft04Boolean' => false],
        'minItems' => ['kind' => RefinementKind::LowerBound, 'absent' => 0.0, 'draft04Boolean' => false],
        'minProperties' => ['kind' => RefinementKind::LowerBound, 'absent' => 0.0, 'draft04Boolean' => false],
        'minimum' => ['kind' => RefinementKind::LowerBound, 'absent' => null, 'draft04Boolean' => false],
        'exclusiveMinimum' => ['kind' => RefinementKind::LowerBound, 'absent' => null, 'draft04Boolean' => true],
        'multipleOf' => ['kind' => RefinementKind::Divisor, 'absent' => null, 'draft04Boolean' => false],
        'uniqueItems' => ['kind' => RefinementKind::Flag, 'absent' => null, 'draft04Boolean' => false],
        // The four with no order between two values at all. A regex containment argument is a real
        // decision procedure nobody should improvise at a release gate, and `const` names one value out
        // of everything: changing either is reported as the change it is, and classed breaking.
        'pattern' => ['kind' => RefinementKind::Opaque, 'absent' => null, 'draft04Boolean' => false],
        'const' => ['kind' => RefinementKind::Opaque, 'absent' => null, 'draft04Boolean' => false],
        'contentEncoding' => ['kind' => RefinementKind::Opaque, 'absent' => null, 'draft04Boolean' => false],
        'contentMediaType' => ['kind' => RefinementKind::Opaque, 'absent' => null, 'draft04Boolean' => false],
        // Read by a comparison of their own, each for a reason of its own. `enum` and `format` are
        // classified members of the diff's own vocabulary; `contentSchema` is a subschema and goes
        // through the position table; and `contains`' two bounds are read beside the keyword they bound,
        // because whether they assert anything is what decides what that keyword's presence is worth.
        'enum' => ['kind' => RefinementKind::Elsewhere, 'absent' => null, 'draft04Boolean' => false],
        'format' => ['kind' => RefinementKind::Elsewhere, 'absent' => null, 'draft04Boolean' => false],
        'contentSchema' => ['kind' => RefinementKind::Elsewhere, 'absent' => null, 'draft04Boolean' => false],
        'minContains' => ['kind' => RefinementKind::Elsewhere, 'absent' => null, 'draft04Boolean' => false],
        'maxContains' => ['kind' => RefinementKind::Elsewhere, 'absent' => null, 'draft04Boolean' => false],
    ];

    /**
     * The rule for `$keyword`. A keyword nobody has decided is {@see RefinementKind::Undecided}, which
     * reads as a change nothing can order — reported, and classed breaking — because silence is the one
     * answer a release gate cannot recover from. A keyword arrives from a document, so the fallback is a
     * runtime one; a {@see RefinementKind} never does, which is why that axis is decided at analysis time.
     *
     * @return Rule
     */
    public static function rule(string $keyword): array
    {
        return self::RULES[$keyword] ?? ['kind' => RefinementKind::Undecided, 'absent' => null, 'draft04Boolean' => false];
    }

    /**
     * What comparing `$keyword` costs a reader, or null where it is no refinement at all and this class
     * has nothing to say about it. That is the filter a caller walking a schema's keywords wants:
     * everything else here is a decision somebody made.
     */
    public static function kindOf(string $keyword): ?RefinementKind
    {
        if (! SchemaKeywords::isRefinement($keyword)) {
            return null;
        }

        return self::rule($keyword)['kind'];
    }

    /**
     * Which way `$keyword` moved between two schemas — asked of a keyword the caller has already
     * recognised as a refinement ({@see kindOf()}), because deciding WHICH keywords to report on is the
     * caller's question. A keyword neither side carries, and one written out at the value its absence
     * already meant, are {@see RefinementMove::Unchanged}; one nobody has decided is not.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    public static function move(string $keyword, array $old, array $new): RefinementMove
    {
        $had = array_key_exists($keyword, $old);
        $has = array_key_exists($keyword, $new);
        $before = $had ? $old[$keyword] : null;
        $after = $has ? $new[$keyword] : null;

        if ($had === $has && ValueKey::of($before) === ValueKey::of($after)) {
            return RefinementMove::Unchanged;
        }

        $rule = self::rule($keyword);

        return match ($rule['kind']) {
            RefinementKind::Elsewhere => RefinementMove::Unchanged,
            RefinementKind::Undecided => RefinementMove::Incomparable,
            RefinementKind::Opaque => self::opaque($had, $has),
            RefinementKind::Flag => self::flag($before, $had, $after, $has),
            RefinementKind::Divisor => self::divisor($before, $had, $after, $has),
            RefinementKind::UpperBound, RefinementKind::LowerBound => self::bound($rule, $before, $had, $after, $has),
        };
    }

    /**
     * Every keyword a decision has been recorded for, so the guard reads this set rather than a second
     * copy of it. {@see SchemaKeywords::refinements()} is the set it is checked against, in both
     * directions — a keyword the draft model learns and a keyword only this table knows are both a
     * decision nobody made.
     *
     * @return list<string>
     */
    public static function decided(): array
    {
        return array_keys(self::RULES);
    }

    /**
     * A ceiling or a floor, in whichever dialect the two sides wrote it.
     *
     * @param  Rule  $rule
     */
    private static function bound(array $rule, mixed $before, bool $had, mixed $after, bool $has): RefinementMove
    {
        // draft-04 spells exclusivity as a boolean modifier on the `minimum`/`maximum` beside it, where
        // 2020-12 spells it as the bound itself. Two booleans ARE that modifier and compare as the flag
        // they are — absent is "not exclusive", and turning it on narrows at either end. A boolean
        // against a NUMBER is two dialects, and ordering those means folding the sibling keyword this
        // comparison does not read, so the direction is what cannot be computed rather than a guess.
        if ($rule['draft04Boolean'] && (is_bool($before) || is_bool($after))) {
            return self::flag($before, $had, $after, $has);
        }

        $was = $rule['absent'];
        $is = $rule['absent'];

        if ($had) {
            $was = self::number($before);
        }

        if ($has) {
            $is = self::number($after);
        }

        if ($was === false || $is === false) {
            return RefinementMove::Incomparable;
        }

        if ($was === $is) {
            return RefinementMove::Unchanged;
        }

        // No bound at all is looser than any bound, whichever end of the range it sits at.
        if ($was === null) {
            return RefinementMove::Narrowed;
        }

        if ($is === null) {
            return RefinementMove::Widened;
        }

        $narrowed = $rule['kind'] === RefinementKind::UpperBound ? $is < $was : $is > $was;

        return $narrowed ? RefinementMove::Narrowed : RefinementMove::Widened;
    }

    /** A boolean that is off where nobody wrote it: off to on narrows, on to off widens. */
    private static function flag(mixed $before, bool $had, mixed $after, bool $has): RefinementMove
    {
        if (($had && ! is_bool($before)) || ($has && ! is_bool($after))) {
            return RefinementMove::Incomparable;
        }

        $was = $had && $before === true;
        $is = $has && $after === true;

        if ($was === $is) {
            return RefinementMove::Unchanged;
        }

        return $is ? RefinementMove::Narrowed : RefinementMove::Widened;
    }

    /**
     * `multipleOf`, where narrower is "a multiple of what it was": the multiples of 4 are a subset of the
     * multiples of 2, and 2 against 3 orders neither way.
     */
    private static function divisor(mixed $before, bool $had, mixed $after, bool $has): RefinementMove
    {
        $was = $had ? self::number($before) : null;
        $is = $has ? self::number($after) : null;

        if ($was === false || $is === false) {
            return RefinementMove::Incomparable;
        }

        if ($was === $is) {
            return RefinementMove::Unchanged;
        }

        if ($was === null) {
            return RefinementMove::Narrowed;
        }

        if ($is === null) {
            return RefinementMove::Widened;
        }

        // The keyword's own domain is the positive numbers; anything else is a value nothing can order.
        if ($was <= 0.0 || $is <= 0.0) {
            return RefinementMove::Incomparable;
        }

        if (self::divides($was, $is)) {
            return RefinementMove::Narrowed;
        }

        return self::divides($is, $was) ? RefinementMove::Widened : RefinementMove::Incomparable;
    }

    /** Whether `$value` is a multiple of `$divisor`, both known positive. */
    private static function divides(float $divisor, float $value): bool
    {
        $quotient = $value / $divisor;

        // A relative tolerance, because a decimal step does not divide exactly in binary: `0.1 / 0.05`
        // is 2.0000000000000004, and reading that as "not a multiple" would report an ordinary
        // relaxation as a change nothing can order.
        return abs($quotient - round($quotient)) <= 1e-9 * max(1.0, abs($quotient));
    }

    /**
     * A keyword whose two values compare only for equality — so all that is left is presence, and the
     * caller has already settled that the values are not the same.
     */
    private static function opaque(bool $had, bool $has): RefinementMove
    {
        if (! $had) {
            return RefinementMove::Narrowed;
        }

        return $has ? RefinementMove::Incomparable : RefinementMove::Widened;
    }

    /** The value as a float, or false where nothing here can read it as one. */
    private static function number(mixed $value): float|false
    {
        if (is_int($value)) {
            return (float) $value;
        }

        return is_float($value) && is_finite($value) ? $value : false;
    }
}
