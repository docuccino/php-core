<?php

declare(strict_types=1);

namespace Docuccino\Core\Canonical;

use JsonException;
use RuntimeException;
use stdClass;

/**
 * Deterministic JSON writer (design §3.5): UTF-8, LF line endings, 2-space indent,
 * a trailing newline, minimal escaping, and shortest round-trip floats.
 *
 * Member order is the caller's responsibility (see {@see Canonicalizer}); this writer
 * preserves the insertion order it is given. Empty objects must be passed as
 * {@see stdClass} so they serialize as `{}` rather than `[]`.
 *
 * @internal
 */
final class CanonicalJsonSerializer
{
    private const string INDENT = '  ';

    private const int ENCODE_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    public function serialize(mixed $value): string
    {
        return $this->encode($value, 0)."\n";
    }

    private function encode(mixed $value, int $depth): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => $this->encodeFloat($value),
            is_string($value) => $this->encodeString($value),
            $value instanceof stdClass => $this->encodeMap((array) $value, $depth),
            is_array($value) => $this->encodeArray($value, $depth),
            default => throw new RuntimeException('Value is not JSON-serialisable: '.get_debug_type($value)),
        };
    }

    /**
     * Encodes a float independent of the ambient `serialize_precision` ini (design §3.5:
     * "shortest-round-trip floats"). `json_encode`'s float formatting is governed by that ini —
     * so we pin it to `-1` (shortest string that round-trips) for the duration of the encode and
     * restore it, making the bytes identical whether the host runs with `serialize_precision` at
     * its default `-1`, at `17`, or anything else.
     *
     * Consequence of shortest-round-trip: an integer-valued float renders WITHOUT a decimal point
     * (`10.0` → `10`), so it is byte-identical to the integer `10`. This is an accepted canonical
     * normalisation — the canonical form does not distinguish `10.0` from `10`.
     */
    private function encodeFloat(float $value): string
    {
        if (! is_finite($value)) {
            throw new RuntimeException('Non-finite floats cannot be serialised to JSON.');
        }

        $previous = ini_set('serialize_precision', '-1');

        try {
            $encoded = json_encode($value, self::ENCODE_FLAGS);
        } catch (JsonException $e) {
            throw new RuntimeException('Failed to encode float.', previous: $e);
        } finally {
            if (is_string($previous)) {
                ini_set('serialize_precision', $previous);
            }
        }

        return $encoded;
    }

    private function encodeString(string $value): string
    {
        try {
            return json_encode($value, self::ENCODE_FLAGS);
        } catch (JsonException $e) {
            throw new RuntimeException('Failed to encode string.', previous: $e);
        }
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private function encodeArray(array $value, int $depth): string
    {
        if ($value === []) {
            return '[]';
        }

        if (array_is_list($value)) {
            return $this->encodeList($value, $depth);
        }

        return $this->encodeMap($value, $depth);
    }

    /**
     * @param  list<mixed>  $value
     */
    private function encodeList(array $value, int $depth): string
    {
        $inner = str_repeat(self::INDENT, $depth + 1);
        $items = [];

        foreach ($value as $item) {
            $items[] = $inner.$this->encode($item, $depth + 1);
        }

        return "[\n".implode(",\n", $items)."\n".str_repeat(self::INDENT, $depth).']';
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private function encodeMap(array $value, int $depth): string
    {
        if ($value === []) {
            return '{}';
        }

        $inner = str_repeat(self::INDENT, $depth + 1);
        $members = [];

        foreach ($value as $key => $item) {
            $members[] = $inner.$this->encodeString((string) $key).': '.$this->encode($item, $depth + 1);
        }

        return "{\n".implode(",\n", $members)."\n".str_repeat(self::INDENT, $depth).'}';
    }
}
