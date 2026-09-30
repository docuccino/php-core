<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract;

use Closure;
use Opis\JsonSchema\JsonPointer;

/**
 * Which of a root's `$defs` a schema can reach through `$ref`, read the way the validator resolves one:
 * a pointer from the root, decoded by the validator's own parser, so an escaped or percent-encoded
 * segment names exactly the member it will resolve to. A schema that names another any other way — an
 * anchor, a base URI, a relative pointer, a template, a dynamic reference — gets null, which means every
 * `$def` has to travel with it.
 *
 * A `$ref` counts only where the validator follows one ({@see SchemaMembers}): never as the name of a
 * property, never inside a value a schema states. A member that names a schema another way counts
 * wherever it stands, instance data included, because the validator registers an id or an anchor
 * wherever it walks.
 *
 * Read in two layouts, because a pointer into the root outside `$defs` means different things in each:
 * the schema itself where the root is the rest of it ({@see of()}), and nothing it can know where it is
 * one `$def` among others ({@see within()}).
 *
 * @internal
 */
final class ReachableDefs
{
    /**
     * Members that let a schema be named some way other than by a pointer from the root — a base URI, an
     * anchor, a dynamic reference, or a dialect with its own rules for those.
     *
     * @var list<string>
     */
    private const array ADDRESSING = ['$id', '$anchor', '$dynamicAnchor', '$dynamicRef', '$recursiveAnchor', '$recursiveRef', '$schema'];

    /**
     * The `$defs` names a schema references directly, or null where it references anything some other way.
     * A pointer into the root anywhere but `$defs` names nothing to add: in a root the schema is the rest
     * of, that is the schema itself.
     *
     * @return list<string>|null
     */
    public static function of(mixed $schema): ?array
    {
        return self::walk($schema, rootIsSelf: true);
    }

    /**
     * As {@see of()}, for a schema stored as one `$def` among others: there a pointer into the root outside
     * `$defs` names whatever the root is, which the schema cannot know, so it gets null too.
     *
     * @return list<string>|null
     */
    public static function within(mixed $schema): ?array
    {
        return self::walk($schema, rootIsSelf: false);
    }

    /**
     * The `$defs` member a pointer into `$defs` names, read as {@see of()} reads it; null for any other reference.
     */
    public static function defNamed(string $ref): ?string
    {
        $name = self::named($ref);

        return is_string($name) ? $name : null;
    }

    /**
     * @return list<string>|null
     */
    private static function walk(mixed $schema, bool $rootIsSelf): ?array
    {
        $names = [];

        return self::collect($schema, SchemaMembers::SCHEMA, $rootIsSelf, $names) ? $names : null;
    }

    /**
     * Adds to `$names` each `$defs` name `$node`, read as `$in`, references — false the moment it names a
     * schema some other way.
     *
     * @param  list<string>  $names
     */
    private static function collect(mixed $node, string $in, bool $rootIsSelf, array &$names): bool
    {
        if (is_array($node)) {
            foreach ($node as $item) {
                if (! self::collect($item, SchemaMembers::item($in), $rootIsSelf, $names)) {
                    return false;
                }
            }

            return true;
        }

        foreach (is_object($node) ? get_object_vars($node) : [] as $member => $value) {
            $member = (string) $member;

            if (in_array($member, self::ADDRESSING, true)) {
                return false;
            }

            if (SchemaMembers::isReference($member, $in)) {
                $name = is_string($value) ? self::named($value) : false;

                if ($name === false || ($name === null && ! $rootIsSelf)) {
                    return false;
                }

                if ($name !== null) {
                    $names[] = $name;
                }

                continue;
            }

            if (! self::collect($value, SchemaMembers::member($member, $in), $rootIsSelf, $names)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every name reachable from `$names`, following each `$def`'s own references — or null, meaning all of
     * them, where any on the way cannot be read. A name no `$def` holds is skipped: that reference resolves
     * to nothing whatever travels with it. `$complete` makes such a name an answer of null instead, for a
     * caller that needs every reference to land. `$reaches` is asked about the names reached and no other,
     * so a caller can read each `$def` the first time one is.
     *
     * @param  list<string>|null  $names
     * @param  Closure(string): (list<string>|false|null)  $reaches  a `$def`'s own {@see of()} or {@see within()},
     *                                                               or false where no `$def` has the name
     * @return array<array-key, true>|null
     */
    public static function closure(?array $names, Closure $reaches, bool $complete = false): ?array
    {
        if ($names === null) {
            return null;
        }

        $reachable = [];

        while ($names !== []) {
            $name = array_pop($names);

            if (isset($reachable[$name])) {
                continue;
            }

            $next = $reaches($name);

            if ($next === false) {
                if ($complete) {
                    return null;
                }

                continue;
            }

            $reachable[$name] = true;

            if ($next === null) {
                return null;
            }

            foreach ($next as $each) {
                $names[] = $each;
            }
        }

        return $reachable;
    }

    /**
     * The `$defs` member a reference points into; null for a pointer anywhere else in the root; false for
     * any reference that is not a plain pointer from the root.
     */
    private static function named(string $ref): string|false|null
    {
        if ($ref === '#') {
            return null;
        }

        // The validator expands a template before it resolves one, so what it names is decided at run time.
        if (! str_starts_with($ref, '#/') || str_contains($ref, '{')) {
            return false;
        }

        $pointer = JsonPointer::parse(substr($ref, 1));

        if ($pointer === null || ! $pointer->isAbsolute()) {
            return false;
        }

        $path = $pointer->path();

        if (($path[0] ?? null) !== '$defs') {
            return null;
        }

        return isset($path[1]) ? (string) $path[1] : false;
    }
}
