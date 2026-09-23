<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff;

use stdClass;

/**
 * A value's identity to the diff, as its JSON text — which is what makes `{}` and `[]` two values and
 * two `stdClass` standing for one JSON object one. An object's members are sorted first, at every depth,
 * because JSON object equality has no member order: the canonical side of a diff has had its values'
 * keys sorted and a fresh build has not, so without it a written example, an object `const` or an object
 * enum member read as changed with nothing changed. A list keeps its order. Where JSON cannot spell a
 * value at all (a string that is not valid UTF-8, an `INF`, a `NAN`) `serialize()` answers instead,
 * faithfully: the fallback was `gettype()`, under which every un-encodable value shared one key, so a
 * removed enum value read as still present and the breaking change went unreported. The prefixes keep
 * the two spaces apart.
 *
 * @internal
 */
final class ValueKey
{
    public static function of(mixed $value): string
    {
        $normalized = self::memberOrderFree($value);
        $encoded = json_encode($normalized);

        return $encoded === false ? 'php:'.serialize($normalized) : 'json:'.$encoded;
    }

    /** A JSON object stays an object, even once its sorted keys would read as a list. */
    private static function memberOrderFree(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            return (object) self::sortedMembers((array) $value);
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::memberOrderFree(...), $value);
        }

        return (object) self::sortedMembers($value);
    }

    /**
     * @param  array<mixed, mixed>  $members
     * @return array<mixed, mixed>
     */
    private static function sortedMembers(array $members): array
    {
        uksort($members, static fn (int|string $a, int|string $b): int => strcmp((string) $a, (string) $b));

        return array_map(self::memberOrderFree(...), $members);
    }
}
