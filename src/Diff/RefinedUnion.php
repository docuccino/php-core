<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff;

use Docuccino\Core\Draft\SchemaKeywords;
use Docuccino\Core\Extensions\Schema\DiscriminatedUnion;
use Docuccino\Core\Support\Hydrate;

/**
 * A union of tagged components refined member by member, read the same in either of its two spellings: the
 * union's pointer with an `anyOf` of tag-pinned refinements beside it (3.0: inside a one-branch `allOf`), or
 * the union's members listed, each refined one as `allOf: [member, refinement]`. Both read here as what each
 * member's refinement says beyond the member itself ({@see reduced()}), so the two spellings of one contract
 * compare equal. The reading is docs/design/uir-and-extensions.md §1 "Reading through a spelling".
 *
 * @internal
 */
final class RefinedUnion
{
    /**
     * @param  array<string, array{at: string, refinement: mixed}>  $members  by member pointer: where its refinement is spelled, and what it says beyond the member
     * @param  array<string, mixed>  $rest  everything else the position says, which both spellings carry alike
     */
    private function __construct(
        public readonly bool $listed,
        public readonly array $members,
        public readonly mixed $discriminator,
        public readonly array $rest,
    ) {}

    /** `$schema` read as a refined union, or null where it spells neither form. */
    public static function of(mixed $schema, ComponentRefs $refs): ?self
    {
        $map = Hydrate::mapOrNull($schema);

        if ($map === null) {
            return null;
        }

        return self::beside($map, $refs) ?? self::listed($map, $refs);
    }

    /**
     * A key naming what a branch refines — a union, by its members, so its two spellings pair as one branch;
     * or one member, refined or bare, so a member refined differently is that member edited rather than
     * another arrived.
     */
    public static function key(mixed $schema, ComponentRefs $refs): ?string
    {
        $map = Hydrate::mapOrNull($schema);
        $bare = $map !== null && ComponentRefs::isBarePointer($map) ? ($map['$ref'] ?? null) : null;
        $member = self::refinedMember($map) ?? (is_string($bare) ? $bare : null);

        if ($member !== null) {
            return "member\0".$member;
        }

        $union = self::of($schema, $refs);

        if ($union === null) {
            return null;
        }

        $members = array_keys($union->members);
        sort($members, SORT_STRING);

        return "union\0".implode("\0", $members);
    }

    /**
     * The member `allOf: [{$ref: member}, refinement]` refines, or null where `$branch` is not that.
     *
     * @param  array<string, mixed>|null  $branch
     */
    private static function refinedMember(?array $branch): ?string
    {
        $all = $branch['allOf'] ?? null;
        $head = is_array($all) && array_is_list($all) && count($all) === 2 && count($branch ?? []) === 1 ? Hydrate::mapOrNull($all[0]) : null;
        $pointer = $head !== null && ComponentRefs::isBarePointer($head) ? ($head['$ref'] ?? null) : null;

        return is_string($pointer) ? $pointer : null;
    }

    /**
     * The pointer form: `{$ref: U, anyOf: [refinement…]}` or `{allOf: [{$ref: U}], anyOf: [refinement…]}`,
     * where U is a union of components each refinement picks one of by the tag it pins.
     *
     * @param  array<string, mixed>  $map
     */
    private static function beside(array $map, ComponentRefs $refs): ?self
    {
        $pointer = $map['$ref'] ?? null;
        $wrapper = [];

        if ($pointer === null) {
            $all = $map['allOf'] ?? null;
            $only = is_array($all) && array_is_list($all) && count($all) === 1 ? Hydrate::mapOrNull($all[0]) : null;
            $pointer = $only !== null && ComponentRefs::isBarePointer($only) ? ($only['$ref'] ?? null) : null;
            $wrapper = ['allOf' => true];
        }

        $refinements = $map['anyOf'] ?? null;

        if (! is_string($pointer) || ! is_array($refinements) || ! array_is_list($refinements)
            || array_keys(ComponentRefs::siblings(array_diff_key($map, $wrapper))) !== ['anyOf']) {
            return null;
        }

        $union = Hydrate::mapOrNull($refs->resolveSchema(['$ref' => $pointer]));
        $members = $union === null ? null : self::members($union['oneOf'] ?? $union['anyOf'] ?? null, $refs);

        if ($members === null || count($refinements) !== count($members[0])) {
            return null;
        }

        [$pointers, $bodies] = $members;
        $tags = DiscriminatedUnion::tags($bodies);
        $picked = [];

        foreach ($refinements as $j => $refinement) {
            $index = self::picks($refinement, $bodies, $tags);

            if ($index === null || isset($picked[$pointers[$index]])) {
                return null;
            }

            $picked[$pointers[$index]] = ['at' => '.anyOf.'.$j, 'refinement' => self::reduced($refinement, ['$ref' => $pointers[$index]], $refs)];
        }

        return new self(false, $picked, $union['discriminator'] ?? null, array_diff_key($map, ['$ref' => true, 'anyOf' => true] + $wrapper));
    }

    /**
     * The listed form: a `oneOf` (or `anyOf`) of components told apart by a tag, at least one of them as
     * `allOf: [{$ref: member}, refinement]`.
     *
     * @param  array<string, mixed>  $map
     */
    private static function listed(array $map, ComponentRefs $refs): ?self
    {
        $keyword = array_key_exists('oneOf', $map) ? 'oneOf' : 'anyOf';
        $branches = $map[$keyword] ?? null;
        $rest = array_diff_key($map, [$keyword => true, 'discriminator' => true]);

        if (! is_array($branches) || ! array_is_list($branches) || count($branches) < 2 || ComponentRefs::siblings($rest) !== []) {
            return null;
        }

        $pointers = [];
        $refined = false;
        $members = [];

        foreach ($branches as $j => $branch) {
            $branch = Hydrate::mapOrNull($branch);
            $pointer = self::refinedMember($branch);
            $refined = $refined || $pointer !== null;
            $pointer ??= $branch !== null && ComponentRefs::isBarePointer($branch) ? ($branch['$ref'] ?? null) : null;

            if (! is_string($pointer) || isset($members[$pointer])) {
                return null;
            }

            $pointers[] = ['$ref' => $pointer];
            $members[$pointer] = ['at' => '.'.$keyword.'.'.$j, 'refinement' => $branch];
        }

        $bodies = $refined ? self::members($pointers, $refs) : null;

        if ($bodies === null || DiscriminatedUnion::tags($bodies[1]) === []) {
            return null;
        }

        foreach ($members as $pointer => $member) {
            $members[$pointer]['refinement'] = self::reduced($member['refinement'], ['$ref' => $pointer], $refs);
        }

        return new self(true, $members, $map['discriminator'] ?? null, $rest);
    }

    /**
     * The pointers a union lists and the bodies they name, or null where a branch is anything but a bare
     * pointer to a registered object.
     *
     * @return array{list<string>, list<array<mixed>>}|null
     */
    private static function members(mixed $branches, ComponentRefs $refs): ?array
    {
        if (! is_array($branches) || ! array_is_list($branches) || count($branches) < 2) {
            return null;
        }

        $pointers = [];
        $bodies = [];

        foreach ($branches as $branch) {
            $branch = Hydrate::mapOrNull($branch);
            $pointer = Hydrate::stringOrNull($branch['$ref'] ?? null);
            $body = $branch !== null && $pointer !== null ? Hydrate::mapOrNull($refs->resolveSchema($branch)) : null;

            if ($pointer === null || $body === null) {
                return null;
            }

            $pointers[] = $pointer;
            $bodies[] = $body;
        }

        return [$pointers, $bodies];
    }

    /**
     * The member a refinement holds of, by the tag value it pins — `const`, or 3.0's one-value `enum`.
     *
     * @param  list<array<mixed>>  $bodies
     * @param  list<string>  $tags
     */
    private static function picks(mixed $refinement, array $bodies, array $tags): ?int
    {
        $properties = Hydrate::mapOrNull(Hydrate::mapOrNull($refinement)['properties'] ?? null) ?? [];

        foreach ($tags as $tag) {
            $pin = Hydrate::mapOrNull($properties[$tag] ?? null) ?? [];
            $enum = $pin['enum'] ?? null;
            $value = $pin['const'] ?? (is_array($enum) && count($enum) === 1 ? reset($enum) : null);

            foreach ($bodies as $i => $body) {
                if ($value !== null && (DiscriminatedUnion::pinned($body)[$tag] ?? null) === $value) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * What `$schema` says beyond `$member` where the two apply to one value together: every keyword the
     * member states identically at the same place set aside, through `properties`, `items` and an `allOf`,
     * and a branch that IS the member gone. A schema with any other subschema position — `additionalProperties`
     * reading the `properties` beside it, a `not` whose polarity runs the other way — is kept whole.
     */
    public static function reduced(mixed $schema, mixed $member, ComponentRefs $refs): mixed
    {
        $map = Hydrate::mapOrNull($schema);
        $mine = Hydrate::mapOrNull($member);

        if ($map === null) {
            return $schema;
        }

        $asserted = self::assertions($map);

        if ($mine !== null && ValueKey::of($asserted) === ValueKey::of(self::assertions($mine))) {
            return [];
        }

        if (count($asserted) === 1 && is_array($map['allOf'] ?? null) && array_is_list($map['allOf'])) {
            return self::merged(array_map(static fn (mixed $branch): mixed => self::reduced($branch, $member, $refs), $map['allOf']), $map);
        }

        if ($mine !== null && is_string($mine['$ref'] ?? null)) {
            $mine = Hydrate::mapOrNull($refs->resolveSchema($mine));
        }

        $rest = $map;
        unset($rest['properties'], $rest['items']);

        if ($mine === null || isset($map['$ref']) || array_filter(array_keys($rest), static fn (string $keyword): bool => SchemaKeywords::positionOf($keyword) !== null) !== []) {
            return $map;
        }

        $out = [];

        foreach ($rest as $keyword => $value) {
            if (! array_key_exists($keyword, $mine) || ValueKey::of($mine[$keyword]) !== ValueKey::of($value)) {
                $out[$keyword] = $value;
            }
        }

        $stated = $mine['required'] ?? null;

        if (is_array($out['required'] ?? null) && is_array($stated)) {
            $left = array_values(array_filter($out['required'], static fn (mixed $name): bool => ! in_array($name, $stated, true)));
            unset($out['required']);
            $out += $left === [] ? [] : ['required' => $left];
        }

        $theirs = Hydrate::mapOrNull($mine['properties'] ?? null) ?? [];
        $kept = [];

        foreach (Hydrate::mapOrNull($map['properties'] ?? null) ?? [] as $name => $property) {
            $left = self::reduced($property, $theirs[$name] ?? null, $refs);

            if ($left !== []) {
                $kept[$name] = $left;
            }
        }

        $items = array_key_exists('items', $map) ? self::reduced($map['items'], $mine['items'] ?? null, $refs) : [];

        return $out + ($kept === [] ? [] : ['properties' => $kept]) + ($items === [] ? [] : ['items' => $items]);
    }

    /**
     * An `allOf`'s reduced branches as one schema where none of them overlaps another, else as the `allOf`.
     *
     * @param  list<mixed>  $branches
     * @param  array<string, mixed>  $map
     */
    private static function merged(array $branches, array $map): mixed
    {
        $left = array_values(array_filter($branches, static fn (mixed $branch): bool => $branch !== []));
        $merged = [];

        foreach ($left as $branch) {
            if (! is_array($branch) || array_intersect_key($merged, $branch) !== []) {
                return ['allOf' => $left] + array_diff_key($map, ['allOf' => true]);
            }

            $merged += $branch;
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $map
     * @return array<string, mixed>
     */
    private static function assertions(array $map): array
    {
        return array_filter($map, static fn (string $keyword): bool => ! ComponentRefs::isAside($keyword), ARRAY_FILTER_USE_KEY);
    }
}
