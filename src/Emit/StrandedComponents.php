<?php

declare(strict_types=1);

namespace Docuccino\Core\Emit;

use Docuccino\Core\Contract\Pointer;
use Docuccino\Core\Extensions\Schema\ComponentNames;

/**
 * A component the document reached only through what an emission drops goes with it. Provenance names
 * the value a winner overrode by the components it referred to, so a component only that trail refers to
 * is kept for the trail — and once the trail is gone, it is a type nothing uses. A component nothing
 * reached before the emission either was put there on purpose (an overlay, a transformer) and stays.
 *
 * @internal
 */
final class StrandedComponents
{
    /** Named by `security`, never by a `$ref`, so reachability says nothing about them. */
    private const string UNREFERENCED_SECTION = 'securitySchemes';

    /**
     * `$after` without the components `$before` reached and it no longer does.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, mixed>
     */
    public static function drop(array $before, array $after): array
    {
        $components = $after['components'] ?? null;
        if (! is_array($components) || $components === []) {
            return $after;
        }

        $reachedBefore = self::reached($before);
        $reachedAfter = self::reached($after);

        foreach ($components as $section => $bucket) {
            if ($section === self::UNREFERENCED_SECTION || ! is_array($bucket)) {
                continue;
            }

            $kept = array_filter(
                $bucket,
                static fn (string|int $name): bool => ! isset($reachedBefore[$section][$name]) || isset($reachedAfter[$section][$name]),
                ARRAY_FILTER_USE_KEY,
            );

            if (count($kept) === count($bucket)) {
                continue;
            }

            if ($kept === []) {
                unset($components[$section]);
            } else {
                $components[$section] = $kept;
            }
        }

        if ($components === []) {
            unset($after['components']);
        } else {
            $after['components'] = $components;
        }

        return $after;
    }

    /**
     * Every component the document reaches from outside `components`, by section and name. A pointer
     * into a component reaches the component.
     *
     * @param  array<array-key, mixed>  $document
     * @return array<string, array<string, true>>
     */
    private static function reached(array $document): array
    {
        $components = is_array($document['components'] ?? null) ? $document['components'] : [];
        unset($document['components']);

        $reached = [];
        $pending = ComponentNames::references($document);

        while ($pending !== []) {
            $at = self::component((string) array_pop($pending));
            if ($at === null || isset($reached[$at[0]][$at[1]])) {
                continue;
            }

            $reached[$at[0]][$at[1]] = true;

            $section = $components[$at[0]] ?? null;
            $body = is_array($section) ? ($section[$at[1]] ?? null) : null;
            if (is_array($body)) {
                array_push($pending, ...ComponentNames::references($body));
            }
        }

        return $reached;
    }

    /**
     * The section and name of the component a local pointer addresses or points into.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function component(string $ref): ?array
    {
        $segments = explode('/', $ref, 5);

        if (count($segments) < 4 || $segments[0] !== '#' || $segments[1] !== 'components' || $segments[2] === '' || $segments[3] === '') {
            return null;
        }

        return [Pointer::unescape($segments[2]), Pointer::unescape($segments[3])];
    }
}
