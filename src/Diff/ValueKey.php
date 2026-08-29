<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff;

/**
 * A value's identity to the diff, as its JSON text — which is what makes `{}` and `[]` two values and
 * two `stdClass` standing for one JSON object one. Where JSON cannot spell it at all (a string that is
 * not valid UTF-8, an `INF`, a `NAN`) `serialize()` answers instead, faithfully: the fallback was
 * `gettype()`, under which every un-encodable value shared one key, so a removed enum value read as
 * still present and the breaking change went unreported. The prefixes keep the two spaces apart.
 *
 * @internal
 */
final class ValueKey
{
    public static function of(mixed $value): string
    {
        $encoded = json_encode($value);

        return $encoded === false ? 'php:'.serialize($value) : 'json:'.$encoded;
    }
}
