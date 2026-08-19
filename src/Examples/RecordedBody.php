<?php

declare(strict_types=1);

namespace Docuccino\Core\Examples;

use JsonException;
use stdClass;

/**
 * Reading a JSON body into the shape the rest of the pipeline expects, and writing it back.
 *
 * Objects decode to associative arrays, which is what every draft, canonicaliser and emitter here
 * takes — except an EMPTY one, which stays a {@see stdClass} because `[]` and `{}` are different
 * claims and an example that swapped one for the other would contradict its own schema. That is the
 * same convention the canonical serializer already asks callers to follow.
 *
 * @internal
 */
final class RecordedBody
{
    /**
     * @throws JsonException when the string is not JSON
     */
    public static function decode(string $json): mixed
    {
        return self::normalize(json_decode($json, false, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * A recording file's bytes: pretty-printed for review, LF-terminated, deterministic for one value.
     *
     * @param  array<string, mixed>  $data
     */
    public static function encode(array $data): ?string
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? null : $json."\n";
    }

    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $out = [];
            $named = false;
            foreach ((array) $value as $key => $child) {
                $out[$key] = self::normalize($child);
                // PHP re-reads `"0"` as the integer 0, so a member name that looks like an index is the
                // one case an array cannot carry: those objects stay objects.
                $named = $named || is_string($key);
            }

            return $named ? $out : (object) $out;
        }

        if (is_array($value)) {
            return array_map(self::normalize(...), $value);
        }

        return $value;
    }
}
