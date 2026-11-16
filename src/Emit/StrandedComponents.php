<?php

declare(strict_types=1);

namespace Docuccino\Core\Emit;

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

        $reachedBefore = ComponentNames::reached($before);
        $reachedAfter = ComponentNames::reached($after);

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
}
