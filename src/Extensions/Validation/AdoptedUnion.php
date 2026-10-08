<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Validation;

use Docuccino\Core\Diff\RefinementMove;
use Docuccino\Core\Diff\SchemaRefinement;
use Docuccino\Core\Draft\SchemaKeywords;
use Docuccino\Core\Extensions\Schema\DiscriminatedUnion;

/**
 * A declared union adopting the tagged object the rules split under it ({@see TaggedBranches}): where a
 * `#[BodyParameter]` names a component whose members are told apart by the partition's tag, with exactly
 * the partition's values, the field publishes that component — the type a client already has — and what
 * the rules prove beyond it rides beside the `$ref` as one refinement per tag value, plus the empty object
 * a tagged member cannot describe. Beside the `$ref` the declaration and the refinement both hold, so what
 * is published is what both accept: a declared bound looser than the rules' gets the rules' beside it, and a
 * null the rules refuse is not offered.
 *
 * The refinements stay request-side on purpose: the component is shared with every response that sends the
 * type, and a bound the server enforces on input is no promise about output. The full rule is in
 * `docs/design/uir-and-extensions.md` §Tagged request objects.
 *
 * @internal
 */
final readonly class AdoptedUnion
{
    /**
     * @param  array<string, mixed>|null  $schema  the adopted field, or null where the declaration cannot adopt
     * @param  list<string>  $wider  where the rules accept more than the declaration, worded for the author
     * @param  string|null  $mismatch  why a declared tagged union could not adopt the partition, for the author
     * @param  list<string>  $unlisted  the members the rules require that the declared type does not list
     */
    private function __construct(
        public ?array $schema,
        public array $wider = [],
        public ?string $mismatch = null,
        public array $unlisted = [],
    ) {}

    /**
     * The declared field written over the rules' split one. Null where the standing node is no partition, or
     * the declaration names no union of components — the declared-shape rule decides those — and a
     * mismatch, with no schema, where it names one told apart some other way.
     *
     * @param  array<string, mixed>  $declared
     * @param  array<string, mixed>  $standing
     * @param  array<string, array<string, mixed>>  $schemas  the component bodies registered so far
     */
    public static function over(array $declared, array $standing, array $schemas, string $path): ?self
    {
        $partition = self::partition($standing);
        $union = self::union($declared, $schemas);
        if ($partition === null || $union === null) {
            return null;
        }

        [$branches, $empty] = $partition;
        [$ref, $members, $nullable] = $union;

        $tag = self::sharedTag($branches, $members);
        if ($tag === null) {
            return new self(null, mismatch: self::describe($branches, $members));
        }

        $byValue = [];
        foreach ($members as $member) {
            $byValue[DiscriminatedUnion::pinned($member)[$tag]] = $member;
        }

        $refinements = [];
        $wider = [];
        $unlisted = [];
        $refines = false;
        foreach ($branches as $branch) {
            $value = DiscriminatedUnion::pinned($branch)[$tag];
            $at = [];
            $missing = [];
            $refinement = self::objectDelta($branch, $byValue[$value], $schemas, $path, $at, $missing, $tag);
            foreach ($at as $field) {
                $wider[] = sprintf('`%s` where `%s` is %s', $field, $tag, $value);
            }
            foreach ($missing as $field) {
                $unlisted[] = sprintf('`%s` where `%s` is %s', $field, $tag, $value);
            }
            $refines = $refines || $refinement !== [];
            $refinements[] = self::pinnedTo($tag, $value, $refinement);
        }

        if (self::admitsNull($standing) && ! $nullable) {
            $wider[] = sprintf('`%s`, which the rules let be null', $path);
        }

        $adopted = ['$ref' => $ref] + ($refines ? ['anyOf' => $refinements] : []);
        // A null the declaration admits and the rules refuse is one the server answers with a 422.
        $others = [...($empty ? [DiscriminatedUnion::EMPTY_OBJECT] : []), ...($nullable && self::admitsNull($standing) ? [['type' => 'null']] : [])];
        $rest = array_diff_key($declared, ['$ref' => true, 'anyOf' => true]);
        $schema = $others === [] ? $adopted + $rest : ['anyOf' => [$adopted, ...$others]] + $rest;

        return new self(SchemaKeywords::declaredOver($schema, $standing), array_values(array_unique($wider)), unlisted: $unlisted);
    }

    /**
     * Whether a declared field names a union of components {@see over()} could adopt a partition by — the
     * same reading, so what is split for a declaration to adopt is what it can adopt.
     *
     * @param  array<string, mixed>  $declared
     * @param  array<string, array<string, mixed>>  $schemas
     */
    public static function namesUnion(array $declared, array $schemas): bool
    {
        return self::union($declared, $schemas) !== null;
    }

    /**
     * The tagged branches of a node the rules split in place, and whether it admits the empty object.
     *
     * @param  array<string, mixed>  $node
     * @return array{0: list<array<mixed>>, 1: bool}|null
     */
    private static function partition(array $node): ?array
    {
        $members = $node['anyOf'] ?? null;
        if (! is_array($members) || ! array_is_list($members)) {
            return null;
        }

        $branches = [];
        $empty = false;
        foreach ($members as $member) {
            if ($member === ['type' => 'null']) {
                continue;
            }

            if (DiscriminatedUnion::isEmptyObject($member)) {
                $empty = true;

                continue;
            }

            if (! is_array($member) || ! is_array($member['properties'] ?? null)) {
                return null;
            }

            $branches[] = $member;
        }

        return DiscriminatedUnion::tags($branches) === [] ? null : [$branches, $empty];
    }

    /**
     * The declared field's union: the `$ref` it names, the bodies of the members that component is a union
     * of, and whether the field admits null beside it — or null where it names no union of components.
     *
     * @param  array<string, mixed>  $declared
     * @param  array<string, array<string, mixed>>  $schemas
     * @return array{0: string, 1: list<array<mixed>>, 2: bool}|null
     */
    private static function union(array $declared, array $schemas): ?array
    {
        $alternatives = $declared['anyOf'] ?? [$declared];
        if (! is_array($alternatives)) {
            return null;
        }

        $refs = [];
        $nullable = false;
        foreach ($alternatives as $alternative) {
            if ($alternative === ['type' => 'null']) {
                $nullable = true;
            } elseif (is_array($alternative) && is_string($alternative['$ref'] ?? null)) {
                $refs[] = $alternative['$ref'];
            } else {
                return null;
            }
        }

        if (count($refs) !== 1) {
            return null;
        }

        $body = DiscriminatedUnion::component($refs[0], $schemas);
        $listed = $body === null ? null : ($body['anyOf'] ?? $body['oneOf'] ?? null);
        if (! is_array($listed) || count($listed) < 2) {
            return null;
        }

        $members = [];
        foreach ($listed as $member) {
            $resolved = is_array($member) && count($member) === 1 && is_string($member['$ref'] ?? null) ? DiscriminatedUnion::component($member['$ref'], $schemas) : null;
            if ($resolved === null) {
                return null;
            }

            $members[] = $resolved;
        }

        return [$refs[0], $members, $nullable];
    }

    /**
     * The tag the rules split by that the declared members are told apart by too, with the same values.
     *
     * @param  list<array<mixed>>  $branches
     * @param  list<array<mixed>>  $members
     */
    private static function sharedTag(array $branches, array $members): ?string
    {
        foreach (array_intersect(DiscriminatedUnion::tags($branches), DiscriminatedUnion::tags($members)) as $tag) {
            if (self::values($branches, $tag) === self::values($members, $tag)) {
                return $tag;
            }
        }

        return null;
    }

    /**
     * @param  list<array<mixed>>  $bodies
     * @return list<string>
     */
    private static function values(array $bodies, string $tag): array
    {
        $values = array_map(static fn (array $body): string => DiscriminatedUnion::pinned($body)[$tag], $bodies);
        sort($values, SORT_STRING);

        return $values;
    }

    /**
     * What each side is told apart by, worded for the author.
     *
     * @param  list<array<mixed>>  $branches
     * @param  list<array<mixed>>  $members
     */
    private static function describe(array $branches, array $members): string
    {
        return sprintf('the rules accept one shape per %s, and the declared type is told apart by %s', self::toldApartBy($branches), self::toldApartBy($members));
    }

    /**
     * @param  list<array<mixed>>  $bodies
     */
    private static function toldApartBy(array $bodies): string
    {
        $tags = DiscriminatedUnion::tags($bodies);
        if ($tags === []) {
            return 'no property its members each fix to a value of their own';
        }

        return sprintf('`%s` (%s)', $tags[0], implode(', ', self::values($bodies, $tags[0])));
    }

    /**
     * What one rule branch says about its object that the declared member does not: the refinements of
     * each member they share, every member the declaration lacks, and the members the rules require that
     * it leaves optional — a required one it lacks noted in `$unlisted`. `$skip` is the tag, which the
     * declared member already pins.
     *
     * @param  array<mixed>  $rules
     * @param  array<mixed>  $declared
     * @param  array<string, array<string, mixed>>  $schemas
     * @param  list<string>  $wider
     * @param  list<string>  $unlisted  the required members the declaration lacks
     * @return array<string, mixed>
     */
    private static function objectDelta(array $rules, array $declared, array $schemas, string $path, array &$wider, array &$unlisted, ?string $skip = null): array
    {
        $ruleMembers = is_array($rules['properties'] ?? null) ? $rules['properties'] : [];
        $declaredMembers = is_array($declared['properties'] ?? null) ? $declared['properties'] : [];

        $properties = [];
        foreach ($ruleMembers as $name => $schema) {
            $name = (string) $name;
            if ($name === $skip || ! is_array($schema)) {
                continue;
            }

            $at = $path.'.'.$name;
            $mine = $declaredMembers[$name] ?? null;
            $delta = is_array($mine)
                ? self::delta($schema, self::resolved($mine, $schemas), $schemas, $at, $wider, $unlisted)
                : array_diff_key($schema, array_flip(SchemaKeywords::annotations()));

            if ($delta !== []) {
                $properties[$name] = $delta;
            }
        }

        $stated = is_array($declared['required'] ?? null) ? $declared['required'] : [];
        $required = array_values(array_filter(
            is_array($rules['required'] ?? null) ? $rules['required'] : [],
            static fn (mixed $name): bool => is_string($name) && $name !== $skip && ! in_array($name, $stated, true),
        ));
        foreach ($required as $name) {
            if (! array_key_exists($name, $declaredMembers)) {
                $unlisted[] = $path.'.'.$name;
            }
        }

        return ($properties === [] ? [] : ['properties' => $properties]) + ($required === [] ? [] : ['required' => $required]);
    }

    /**
     * The refinements one rule schema adds to the declared schema of the same member, through its items and
     * members: one the declaration does not state, where its type does not rule it out
     * ({@see SchemaKeywords::survivor()}), and one it states looser — beside a `$ref` both hold, so the rules'
     * tighter bound is what the server enforces. A declared type or bound the rules accept more than is noted
     * in `$wider`.
     *
     * @param  array<mixed>  $rules
     * @param  array<mixed>  $declared
     * @param  array<string, array<string, mixed>>  $schemas
     * @param  list<string>  $wider
     * @param  list<string>  $unlisted
     * @return array<string, mixed>
     */
    private static function delta(array $rules, array $declared, array $schemas, string $path, array &$wider, array &$unlisted): array
    {
        /** @var array<string, mixed> $declared */
        if (self::accepts($rules, $declared) === false) {
            $wider[] = $path;
        }

        $out = [];
        foreach ($rules as $keyword => $value) {
            $keyword = (string) $keyword;
            if (! SchemaKeywords::isRefinement($keyword)) {
                continue;
            }

            [$narrower, $looser] = array_key_exists($keyword, $declared) ? self::compared($keyword, $value, $declared[$keyword]) : [false, true];
            if ($narrower) {
                $wider[] = $path;
            }

            $survivor = $looser ? SchemaKeywords::survivor(array_diff_key($declared, [$keyword => true]), $keyword, $value) : null;
            if ($survivor !== null) {
                $out[$keyword] = $survivor[0];
            }
        }

        if (is_array($rules['items'] ?? null) && is_array($declared['items'] ?? null)) {
            $items = self::delta($rules['items'], self::resolved($declared['items'], $schemas), $schemas, $path.'.*', $wider, $unlisted);
            if ($items !== []) {
                $out['items'] = $items;
            }
        }

        if (is_array($rules['properties'] ?? null) && is_array($declared['properties'] ?? null)) {
            $out += self::objectDelta($rules, $declared, $schemas, $path, $wider, $unlisted);
        }

        return $out;
    }

    /**
     * Whether a declared value of a refinement keyword refuses a value the rules' accepts, and whether the
     * rules' refuses one the declared value accepts. Two value lists are compared by their members, a bound
     * by its direction ({@see SchemaRefinement::move()}); two values nothing orders are not called narrower,
     * and the rules' is kept beside the declared one, both being true of what the server takes.
     *
     * @return array{0: bool, 1: bool}
     */
    private static function compared(string $keyword, mixed $rules, mixed $declared): array
    {
        if ($rules === $declared) {
            return [false, false];
        }

        if ($keyword === 'enum' || $keyword === 'const') {
            $ruled = $keyword === 'enum' ? (is_array($rules) ? $rules : []) : [$rules];
            $stated = $keyword === 'enum' ? (is_array($declared) ? $declared : []) : [$declared];
            $outside = static fn (array $values, array $of): bool => array_filter($values, static fn (mixed $value): bool => ! in_array($value, $of, true)) !== [];

            return [$outside($ruled, $stated), $outside($stated, $ruled)];
        }

        return match (SchemaRefinement::move($keyword, [$keyword => $rules], [$keyword => $declared])) {
            RefinementMove::Narrowed => [true, false],
            RefinementMove::Widened => [false, true],
            default => [false, true],
        };
    }

    /**
     * Whether every type the rules accept is one the declaration admits — null where either states none.
     *
     * @param  array<mixed>  $rules
     * @param  array<mixed>  $declared
     */
    private static function accepts(array $rules, array $declared): ?bool
    {
        $types = static fn (mixed $type): array => array_values(array_filter(is_array($type) ? $type : [$type], is_string(...)));
        $ruled = $types($rules['type'] ?? null);
        $stated = $types($declared['type'] ?? null);
        if ($ruled === [] || $stated === []) {
            return null;
        }

        // A number admits every integer.
        if (in_array('number', $stated, true)) {
            $stated[] = 'integer';
        }

        return array_diff($ruled, $stated) === [];
    }

    /**
     * A declared schema with a lone `$ref` read through to the component it names.
     *
     * @param  array<mixed>  $schema
     * @param  array<string, array<string, mixed>>  $schemas
     * @return array<mixed>
     */
    private static function resolved(array $schema, array $schemas): array
    {
        $ref = $schema['$ref'] ?? null;

        return is_string($ref) ? (DiscriminatedUnion::component($ref, $schemas) ?? $schema) : $schema;
    }

    /**
     * One tag value's refinement, the tag pinned so it holds of that value's members alone.
     *
     * @param  array<string, mixed>  $refinement
     * @return array<string, mixed>
     */
    private static function pinnedTo(string $tag, string $value, array $refinement): array
    {
        $properties = is_array($refinement['properties'] ?? null) ? $refinement['properties'] : [];

        return ['properties' => [$tag => ['const' => $value]] + $properties] + $refinement;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function admitsNull(array $node): bool
    {
        return is_array($node['anyOf'] ?? null) && in_array(['type' => 'null'], $node['anyOf'], true);
    }
}
