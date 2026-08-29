<?php

declare(strict_types=1);

namespace Docuccino\Core\Document;

use Docuccino\Core\Contract\Pointer;
use Docuccino\Core\Contract\Refs;
use Docuccino\Core\Support\Hydrate;

/**
 * Navigating a UIR document in its array form: where its operations are, which components reach a given
 * identity, and the node at a key path. Nothing here knows what a caller is looking for the nodes FOR.
 *
 * A component is addressed by its canonical `#/components/<section>/<name>` pointer throughout, minted
 * and read in one place ({@see componentPointer()}, {@see componentParts()}) — a bag keyed one way and a
 * `$ref` read the other is a lookup that silently finds nothing.
 *
 * @phpstan-type OperationSite array{keys: list<string>, signature: string|null, operationId: string|null}
 *
 * @internal
 */
final class DocumentGraph
{
    /**
     * Every operation the document publishes, with the names a caller may address it by. A webhook is
     * indexed too — a schema it carries is a place a shape appears — but it has no signature: a webhook
     * is a request the SERVER makes, and no client addresses it by method and path.
     *
     * A path item may be written as a `$ref` into `components.pathItems`, and an overlay can introduce
     * one after a build assembled the document. Read as written it states no method at all, so its
     * operations would be invisible here while {@see componentsReaching()} happily followed the same
     * pointer. So the pointer is followed, through the same {@see Refs} every other reader in the
     * product uses. `keys` address the node a reader would go and edit; the SIGNATURE stays the use
     * site's, which is what a request binds against.
     *
     * @param  array<string, mixed>  $doc
     * @return list<OperationSite>
     */
    public static function operationSites(array $doc): array
    {
        $sites = [];

        foreach (['paths', 'webhooks'] as $section) {
            $items = $doc[$section] ?? null;
            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $name => $item) {
                if (! is_array($item)) {
                    continue;
                }

                /** @var array<string, mixed> $item */
                [$resolved, $at] = Refs::follow($doc, $item, [$section, (string) $name]);

                foreach (PathItem::METHODS as $method) {
                    $operation = $resolved[$method] ?? null;
                    if (! is_array($operation)) {
                        continue;
                    }

                    $operationId = $operation['operationId'] ?? null;

                    $sites[] = [
                        'keys' => [...$at, $method],
                        'signature' => $section === 'paths' ? strtoupper($method).' '.$name : null,
                        'operationId' => is_string($operationId) ? $operationId : null,
                    ];
                }
            }
        }

        return $sites;
    }

    /**
     * Which components carry the identity, following component-to-component `$ref`s to a fixpoint. Read
     * once per question rather than walked per operation, and to a fixpoint rather than recursively,
     * because a schema that refers to itself is a legal document and an unbounded walk of one is not.
     *
     * @param  array<string, mixed>  $doc
     * @return array<string, bool>
     */
    public static function componentsReaching(array $doc, string $id): array
    {
        $components = $doc['components'] ?? null;
        if (! is_array($components)) {
            return [];
        }

        $reaches = [];
        $refs = [];
        foreach ($components as $section => $members) {
            if (! is_array($members)) {
                continue;
            }

            foreach ($members as $name => $body) {
                $pointer = self::componentPointer((string) $section, (string) $name);
                $reaches[$pointer] = is_array($body) && self::nodeReaches($body, $id, []);
                $refs[$pointer] = is_array($body) ? self::refsIn($body) : [];
            }
        }

        do {
            $changed = false;
            foreach ($refs as $pointer => $targets) {
                if ($reaches[$pointer]) {
                    continue;
                }

                foreach ($targets as $target) {
                    if ($reaches[$target] ?? false) {
                        $reaches[$pointer] = true;
                        $changed = true;

                        break;
                    }
                }
            }
        } while ($changed);

        return $reaches;
    }

    /**
     * Whether a node carries the identity itself, or a `$ref` to a component that does.
     *
     * @param  array<array-key, mixed>  $node
     * @param  array<string, bool>  $reaches  from {@see componentsReaching()}
     */
    public static function nodeReaches(array $node, string $id, array $reaches): bool
    {
        $docuccino = $node['x-docuccino'] ?? null;
        if (is_array($docuccino) && ($docuccino['id'] ?? null) === $id) {
            return true;
        }

        $ref = self::componentRef($node);
        if ($ref !== null && ($reaches[$ref] ?? false)) {
            return true;
        }

        foreach ($node as $value) {
            if (is_array($value) && self::nodeReaches($value, $id, $reaches)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the identity is written anywhere under this node, `$ref`s not followed. What tells "the
     * document publishes nothing for that thing" from "it publishes it, but not where you looked".
     *
     * @param  array<array-key, mixed>  $node
     */
    public static function carries(array $node, string $id): bool
    {
        $docuccino = $node['x-docuccino'] ?? null;
        if (is_array($docuccino) && ($docuccino['id'] ?? null) === $id) {
            return true;
        }

        foreach ($node as $value) {
            if (is_array($value) && self::carries($value, $id)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every identity written anywhere in a node, as a set.
     *
     * @param  array<array-key, mixed>  $node
     * @return array<string, true>
     */
    public static function identitiesIn(array $node): array
    {
        $docuccino = $node['x-docuccino'] ?? null;
        $id = is_array($docuccino) ? $docuccino['id'] ?? null : null;

        $found = is_string($id) ? [$id => true] : [];

        foreach ($node as $key => $value) {
            if ($key !== 'x-docuccino' && is_array($value)) {
                $found += self::identitiesIn($value);
            }
        }

        return $found;
    }

    /**
     * The component this node is a `$ref` to, as its canonical pointer, or null when it is not one. Only
     * an in-document `#/components/<section>/<name>` counts: an external `$ref` names a file no build
     * read.
     *
     * @param  array<array-key, mixed>  $node
     */
    public static function componentRef(array $node): ?string
    {
        $ref = $node['$ref'] ?? null;
        $parts = is_string($ref) ? self::componentParts($ref) : null;

        return $parts === null ? null : self::componentPointer($parts[0], $parts[1]);
    }

    /**
     * The section and name a local component pointer addresses, unescaped, or null when the pointer
     * addresses no whole component.
     *
     * The RFC 6901 escapes are not decoration: a name carrying a `/` or a `~` is spelled `~1`/`~0` in
     * every pointer to it, so a reader comparing the raw text finds nothing and calls a perfectly
     * resolvable reference dangling. A token with a `/` still in it after that names something INSIDE a
     * component rather than the component.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function componentParts(string $ref): ?array
    {
        if (! str_starts_with($ref, '#/')) {
            return null;
        }

        $segments = explode('/', substr($ref, 2));

        if (count($segments) !== 3 || $segments[0] !== 'components' || $segments[1] === '' || $segments[2] === '') {
            return null;
        }

        return [Pointer::unescape($segments[1]), Pointer::unescape($segments[2])];
    }

    /** The canonical pointer to one component. The inverse of {@see componentParts()}. */
    public static function componentPointer(string $section, string $name): string
    {
        return '#'.Pointer::of(['components', $section, $name]);
    }

    /**
     * The body of the component a canonical pointer addresses.
     *
     * @param  array<string, mixed>  $doc
     * @return array<array-key, mixed>|null
     */
    public static function componentBody(array $doc, string $pointer): ?array
    {
        $parts = self::componentParts($pointer);
        $body = $parts === null ? null : self::at($doc, ['components', $parts[0], $parts[1]]);

        return is_array($body) ? $body : null;
    }

    /**
     * The node at a key path, or whatever non-array the walk ran into.
     *
     * @param  array<array-key, mixed>  $node
     * @param  list<string>  $keys
     */
    public static function at(array $node, array $keys): mixed
    {
        foreach ($keys as $key) {
            $next = $node[$key] ?? null;
            if (! is_array($next)) {
                return $next;
            }

            $node = $next;
        }

        return $node;
    }

    /**
     * The document with one key path replaced, hydrating whatever was not a map on the way down.
     *
     * @param  array<string, mixed>  $node
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public static function with(array $node, array $keys, mixed $value): array
    {
        $key = array_shift($keys);
        if ($key === null) {
            return $node;
        }

        if ($keys === []) {
            $node[$key] = $value;

            return $node;
        }

        $node[$key] = self::with(Hydrate::map($node[$key] ?? null), $keys, $value);

        return $node;
    }

    /**
     * Every component a node points at, at any depth, as canonical pointers.
     *
     * @param  array<array-key, mixed>  $node
     * @return list<string>
     */
    private static function refsIn(array $node): array
    {
        $refs = [];

        $ref = self::componentRef($node);
        if ($ref !== null) {
            $refs[] = $ref;
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $refs = [...$refs, ...self::refsIn($value)];
            }
        }

        return $refs;
    }
}
