<?php

declare(strict_types=1);

namespace Docuccino\Core\Canonical;

use Docuccino\Core\Support\Json;
use Docuccino\Core\Support\JsonValue;
use JsonException;
use RuntimeException;
use stdClass;

/**
 * Deterministic JSON writer: UTF-8, LF line endings, 2-space indent, trailing newline, minimal
 * escaping, shortest round-trip floats.
 *
 * Member order is the caller's job ({@see Canonicalizer}) — this writer keeps the insertion order
 * it's given. Pass empty objects as {@see stdClass} so they serialize as `{}`, not `[]`.
 *
 * @internal
 */
final class CanonicalJsonSerializer
{
    private const string INDENT = '  ';

    /**
     * How deep the descent goes before it refuses — the bound {@see Json} and {@see JsonValue} carry, and
     * far under the 512 {@see JsonValue::decode()} reads at. Recursion with no bound is a stack overflow,
     * which is SIGSEGV with no message rather than an exception: no partial output, no diagnostic, nothing
     * to catch. Every author-controlled reader caps its own input already, so this is the writer refusing
     * in the one way it can be told about instead of the one it cannot.
     */
    private const int MAX_DEPTH = 128;

    private const int ENCODE_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    public function serialize(mixed $value): string
    {
        return $this->encode($value, 0)."\n";
    }

    /**
     * Why this writer would refuse the value, or null when it would take it. A reader pulling a value
     * INTO a document — an example out of a YAML file, an attribute argument — owes whoever wrote it a
     * diagnostic naming where it came from; finding out at emit time is an exception with no file, no
     * attribute and no route in it, and a dead build to go with it.
     */
    public function rejects(mixed $value): ?string
    {
        try {
            $this->encode($value, 0);

            return null;
        } catch (RuntimeException $e) {
            return rtrim($e->getMessage(), '.');
        }
    }

    private function encode(mixed $value, int $depth): string
    {
        if ($depth >= self::MAX_DEPTH && (is_array($value) || $value instanceof stdClass)) {
            throw new RuntimeException(sprintf('Value nests more than %d levels deep.', self::MAX_DEPTH));
        }

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
     * `json_encode`'s float formatting follows the ambient `serialize_precision`, so we pin it to
     * `-1` (shortest round-trip) for the encode and restore it — the bytes come out the same
     * whatever the host is configured with.
     *
     * Side effect: an integer-valued float loses its decimal point (`10.0` → `10`), so it's
     * byte-identical to the int `10`. The canonical form doesn't distinguish the two.
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
