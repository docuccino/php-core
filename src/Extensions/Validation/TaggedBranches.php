<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Validation;

use Docuccino\Core\Draft\SchemaKeywords;
use Docuccino\Core\Extensions\Schema\ComponentNames;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\DiscriminatedUnion;
use Docuccino\Core\Extensions\Schema\EnumDecoration;

/**
 * Publishes each {@see TaggedVariants} object of a request body as the union of its branches: one component
 * per tag value, holding the tag pinned to that value and the members that value keeps, each required where
 * the value requires it. The union is written as an `anyOf` of the components and left to
 * {@see DiscriminatedUnion} to discriminate once the document is complete, so a union recovered from rules
 * reads exactly like one recovered from classes.
 *
 * It runs on the FINISHED body, after the source class's declarations are written, so a docblock or an
 * attribute on a member reaches every branch that carries it. An object whose schema no longer has the shape
 * the rules left — a declaration replaced it, or a keyword constraining its members sits on it — cannot be
 * split, and its rules had their presence moved onto the partition: it is put back as the body's merged
 * reading publishes it, exactly as though no partition had been proved, and every variant inside it with it.
 *
 * An object a `#[BodyParameter]` names is split INLINE instead ({@see adoptable()}): no component is
 * registered for its branches, because the declaration written over them next decides what is published
 * there ({@see AdoptedUnion}), and a component nothing references would be a type nobody can send.
 *
 * @internal
 *
 * @phpstan-import-type TaggedBranch from TaggedVariants
 */
final class TaggedBranches
{
    /** The keywords that say what the object's members are, which the branches take over. */
    private const MEMBER_KEYWORDS = ['type', 'properties', 'required'];

    /**
     * Keywords besides the annotations an object may carry and still be split: bounds on how many members
     * it has hold of every branch alike. Anything else on it — a keyword constraining its members, a
     * composition — says something about the members the split could change, so it is left merged.
     */
    private const OBJECT_BOUNDS = ['minProperties', 'maxProperties'];

    /** Where a named member's schema sits in its object. */
    private const PROPERTIES = 'properties';

    /**
     * The body with every provable object split into branches, each registered as a component named for the
     * body (`$base`, identified as `$identity`), the object's path and the value's word — and the variants
     * published. The objects at `$inline` are split with their branches written in place, and where there is
     * no body component to name branches after (`$base` null) every other object is put back. `$merged` is
     * the body as its merged reading publishes it, which every object not split is put back to.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $merged
     * @param  list<TaggedVariants>  $variants
     * @param  list<string>  $inline
     * @return array{0: array<string, mixed>, 1: list<TaggedVariants>}
     */
    public static function apply(array $schema, array $merged, array $variants, ComponentRegistry $components, ?string $base, string $identity = '', array $inline = []): array
    {
        $prefix = $base === null ? null : ComponentNames::stem($base, $identity);

        // Deepest first, so an object tagged inside another is already a union when the outer one's
        // branches copy it.
        usort($variants, static fn (TaggedVariants $a, TaggedVariants $b): int => self::depth($b) <=> self::depth($a) ?: strcmp($a->path, $b->path));

        // Decided before anything is registered, so a variant given up leaves no component behind. Splitting
        // an object changes only what lies under it, and no variant's object lies under another's tag, so
        // each answer holds on the body the deeper splits leave.
        $restored = [];
        foreach ($variants as $variant) {
            $node = self::node($schema, self::segments($variant));
            $named = $prefix !== null || in_array($variant->path, $inline, true);
            if (! $named || $node === null || self::shape($node, $variant) === null) {
                [$schema, $restored[]] = self::restore($schema, $merged, self::segments($variant));
            }
        }

        $published = array_values(array_filter(
            $variants,
            static fn (TaggedVariants $variant): bool => array_filter($restored, static fn (array $at): bool => self::under(self::segments($variant), $at)) === [],
        ));

        foreach ($published as $variant) {
            $mint = in_array($variant->path, $inline, true) ? null : $prefix;
            $schema = self::at($schema, self::segments($variant), $variant, $components, $mint, $identity);
        }

        return [$schema, $published];
    }

    /**
     * The paths of the variants a declaration names exactly, which are split inline for the declaration to
     * adopt ({@see AdoptedUnion}) — less any with another variant above or below it, whose branches would
     * copy or hold the one adopted: that object keeps what it would have had with no adoption to make.
     *
     * @param  list<TaggedVariants>  $variants
     * @param  list<string>  $declared  the field paths declarations name
     * @return list<string>
     */
    public static function adoptable(array $variants, array $declared): array
    {
        $named = array_map(static fn (string $path): array => FieldPath::segments($path), array_filter($declared, FieldPath::isWellFormed(...)));

        $paths = [];
        foreach ($variants as $variant) {
            $segments = self::segments($variant);
            if ($segments === [] || ! in_array($segments, $named, true)) {
                continue;
            }

            foreach ($variants as $other) {
                if ($other !== $variant && (self::under(self::segments($other), $segments) || self::under($segments, self::segments($other)))) {
                    continue 2;
                }
            }

            $paths[] = $variant->path;
        }

        sort($paths, SORT_STRING);

        return $paths;
    }

    /** @return list<string> */
    private static function segments(TaggedVariants $variant): array
    {
        return $variant->path === '' ? [] : FieldPath::segments($variant->path);
    }

    private static function depth(TaggedVariants $variant): int
    {
        return count(self::segments($variant));
    }

    /**
     * @param  list<string>  $segments
     * @param  list<string>  $at
     */
    private static function under(array $segments, array $at): bool
    {
        return array_slice($segments, 0, count($at)) === $at;
    }

    /**
     * The node a path names, or null where the body has none there.
     *
     * @param  array<string, mixed>  $node
     * @param  list<string>  $segments
     * @return array<string, mixed>|null
     */
    private static function node(array $node, array $segments): ?array
    {
        foreach ($segments as $segment) {
            $child = self::child($node, $segment);
            if ($child === null) {
                return null;
            }

            $node = $child;
        }

        return $node;
    }

    /**
     * Where a segment's child sits in a node: `properties` by name, or for a `*` an array's `items` or a
     * map's `additionalProperties` ({@see FieldNode}) — null where the node has no such child.
     *
     * @param  array<string, mixed>  $node
     */
    private static function slot(array $node, string $segment): ?string
    {
        if ($segment !== '*') {
            $properties = $node['properties'] ?? null;

            return is_array($properties) && is_array($properties[$segment] ?? null) ? self::PROPERTIES : null;
        }

        $slot = is_array($node['items'] ?? null) ? 'items' : 'additionalProperties';

        return is_array($node[$slot] ?? null) ? $slot : null;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>|null
     */
    private static function child(array $node, string $segment): ?array
    {
        $slot = self::slot($node, $segment);
        $child = match ($slot) {
            null => null,
            self::PROPERTIES => is_array($node['properties'] ?? null) ? ($node['properties'][$segment] ?? null) : null,
            default => $node[$slot] ?? null,
        };

        /** @var array<string, mixed>|null */
        return is_array($child) ? $child : null;
    }

    /**
     * The node with the child a segment names replaced; the caller has found that child ({@see slot()}).
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $child
     * @return array<string, mixed>
     */
    private static function withChild(array $node, string $segment, array $child): array
    {
        $slot = self::slot($node, $segment);
        if ($slot !== self::PROPERTIES) {
            $node[$slot ?? 'items'] = $child;

            return $node;
        }

        $properties = is_array($node['properties'] ?? null) ? $node['properties'] : [];
        $properties[$segment] = $child;
        $node['properties'] = $properties;

        return $node;
    }

    /**
     * The body with the node at a path put back as `$merged` has it, and the path it was put back at: the
     * deepest one both bodies have a node for, so whatever the two disagree on is covered.
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $merged
     * @param  list<string>  $segments
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private static function restore(array $node, array $merged, array $segments): array
    {
        $segment = array_shift($segments);
        if ($segment === null) {
            return [$merged, []];
        }

        $child = self::child($node, $segment);
        $mergedChild = self::child($merged, $segment);
        if ($child === null || $mergedChild === null || self::slot($node, $segment) !== self::slot($merged, $segment)) {
            return [$merged, []];
        }

        [$child, $at] = self::restore($child, $mergedChild, $segments);

        return [self::withChild($node, $segment, $child), [$segment, ...$at]];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<string>  $segments
     * @return array<string, mixed>
     */
    private static function at(array $node, array $segments, TaggedVariants $variant, ComponentRegistry $components, ?string $prefix, string $identity): array
    {
        $segment = array_shift($segments);
        if ($segment === null) {
            return self::split($node, $variant, $components, $prefix, $identity) ?? $node;
        }

        $child = self::child($node, $segment);

        return $child === null ? $node : self::withChild($node, $segment, self::at($child, $segments, $variant, $components, $prefix, $identity));
    }

    /**
     * The object's tag and whether it admits null, or null where its schema is not the one the rules left.
     *
     * @param  array<string, mixed>  $node
     * @return array{0: bool, 1: array<mixed>, 2: array<mixed>}|null
     */
    private static function shape(array $node, TaggedVariants $variant): ?array
    {
        $nullable = self::nullable($node);
        $properties = $node['properties'] ?? null;
        if ($nullable === null || ! is_array($properties) || ! is_array($properties[$variant->tag] ?? null)) {
            return null;
        }

        unset($node['anyOf']);
        $allowed = [...self::MEMBER_KEYWORDS, ...self::OBJECT_BOUNDS, ...SchemaKeywords::annotations()];
        if (array_diff(array_map(strval(...), array_keys($node)), $allowed) !== []) {
            return null;
        }

        return [$nullable, $properties, $properties[$variant->tag]];
    }

    /**
     * The object as an `anyOf` of its branches — registered under `$prefix`, or written in place where it is
     * null — or null where its schema is not the one the rules left.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>|null
     */
    private static function split(array $node, TaggedVariants $variant, ComponentRegistry $components, ?string $prefix, string $identity): ?array
    {
        $shape = self::shape($node, $variant);
        if ($shape === null) {
            return null;
        }

        [$nullable, $properties, $tag] = $shape;
        unset($node['anyOf']);
        $required = is_array($node['required'] ?? null) ? array_values(array_filter($node['required'], is_string(...))) : [];

        $members = [];
        foreach ($variant->branches as $branch) {
            $body = self::branch($properties, $required, $tag, $variant, $branch);
            $id = $identity.'/'.ltrim($variant->path.'.'.$variant->tag, '.').'='.$branch['value'];
            $members[] = $prefix === null ? $body : $components->reference($prefix.$branch['name'], $body, $id);
        }

        if ($variant->admitsEmpty) {
            $members[] = DiscriminatedUnion::EMPTY_OBJECT;
        }

        if ($nullable) {
            $members[] = ['type' => 'null'];
        }

        return ['anyOf' => $members] + array_diff_key($node, array_flip(self::MEMBER_KEYWORDS));
    }

    /**
     * Whether the object admits null, or null where its type says it is something besides an object — the
     * two spellings the nullable policy gives it, a type list and an `anyOf` of the object and null.
     *
     * @param  array<string, mixed>  $node
     */
    private static function nullable(array $node): ?bool
    {
        if (array_key_exists('anyOf', $node)) {
            return $node['anyOf'] === [['type' => 'object'], ['type' => 'null']] && ! array_key_exists('type', $node) ? true : null;
        }

        $type = $node['type'] ?? 'object';
        $types = is_array($type) ? $type : [$type];

        return match (true) {
            $types === ['object'] => false,
            $types === ['object', 'null'], $types === ['null', 'object'] => true,
            default => null,
        };
    }

    /**
     * The tag as the object published it, narrowed to one value: a reference to its enum stays one, since
     * that is the type a client names the tag by, and an inline value set gives way to the value.
     *
     * @param  array<mixed>  $tag
     * @return array<mixed>
     */
    private static function pinned(array $tag, string $value): array
    {
        $dropped = array_flip(['enum', 'example', 'examples', ...EnumDecoration::KEYS]);

        return array_diff_key($tag, $dropped) + ['const' => $value];
    }

    /**
     * One branch: the tag pinned to its value, every member but the gated ones the value drops, and the
     * required list the merged object stated plus what this value requires — tag first.
     *
     * @param  array<mixed>  $properties
     * @param  list<string>  $required
     * @param  array<mixed>  $tag
     * @param  TaggedBranch  $branch
     * @return array<string, mixed>
     */
    private static function branch(array $properties, array $required, array $tag, TaggedVariants $variant, array $branch): array
    {
        $pinned = self::pinned($tag, $branch['value']);

        $kept = [];
        foreach ($properties as $name => $schema) {
            $name = (string) $name;
            if ($name === $variant->tag) {
                $kept[$name] = $pinned;
            } elseif (! in_array($name, $variant->gated, true) || in_array($name, $branch['members'], true)) {
                $kept[$name] = $schema;
            }
        }

        $names = [$variant->tag];
        foreach ([...$required, ...$branch['required']] as $name) {
            if (array_key_exists($name, $kept) && ! in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return ['type' => 'object', 'properties' => $kept, 'required' => $names];
    }
}
