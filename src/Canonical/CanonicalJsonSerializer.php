<?php

declare(strict_types=1);

namespace Docuccino\Core\Canonical;

use Closure;
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

    /**
     * `json_encode` escapes every newline inside a string, so each one it writes starts a line of indent,
     * four spaces a level, and nothing else in its output does. `(*LF)` holds PCRE to reading only that as
     * a newline, whatever it was built to read: one built to read any would find NEL in the second byte of `Å`.
     */
    private const string REINDENT = '/(*LF)(?:^|\G) {4}/m';

    public function serialize(mixed $value): string
    {
        return $this->native($value) ?? $this->encode($value, 0)."\n";
    }

    /**
     * The same bytes from `json_encode`, which writes them in C rather than a call per node, for a value
     * {@see plain()} admits; null for anything else, which {@see encode()} then writes or refuses.
     */
    private function native(mixed $value): ?string
    {
        if (! $this->plain($value, 0)) {
            return null;
        }

        try {
            $encoded = $this->shortestFloats(
                static fn (): string => json_encode($value, self::ENCODE_FLAGS | JSON_PRETTY_PRINT, self::MAX_DEPTH),
            );
        } catch (JsonException) {
            // A float or a string with no JSON form, which the walk refuses in its own words.
            return null;
        }

        $indented = preg_replace(self::REINDENT, self::INDENT, $encoded);

        return $indented === null ? null : $indented."\n";
    }

    /**
     * Whether `json_encode` reads the value as {@see encode()} does: arrays, scalars, and `stdClass` itself
     * with no member named from NUL, which it drops, within {@see MAX_DEPTH}. Like the walk it asks each
     * node its type and nothing more, so an object it declines is never called into or looked inside; the
     * type tests are qualified so each compiles to the check it names, not a lookup per node.
     */
    private function plain(mixed $value, int $depth): bool
    {
        if (! \is_array($value)) {
            if (! \is_object($value) || $value::class !== stdClass::class) {
                return \is_scalar($value) || $value === null;
            }

            $value = (array) $value;

            foreach (array_keys($value) as $name) {
                if (str_starts_with((string) $name, "\0")) {
                    return false;
                }
            }
        }

        if ($depth >= self::MAX_DEPTH) {
            return false;
        }

        foreach ($value as $member) {
            if (! \is_scalar($member) && ! $this->plain($member, $depth + 1)) {
                return false;
            }
        }

        return true;
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
     * An integer-valued float loses its decimal point (`10.0` → `10`), so it's byte-identical to the
     * int `10`. The canonical form doesn't distinguish the two.
     */
    private function encodeFloat(float $value): string
    {
        if (! is_finite($value)) {
            throw new RuntimeException('Non-finite floats cannot be serialised to JSON.');
        }

        try {
            return $this->shortestFloats(static fn (): string => json_encode($value, self::ENCODE_FLAGS));
        } catch (JsonException $e) {
            throw new RuntimeException('Failed to encode float.', previous: $e);
        }
    }

    /**
     * `json_encode`'s float formatting follows the ambient `serialize_precision`, so both writers run it
     * pinned to `-1` (shortest round-trip) and restore it — the bytes come out the same whatever the host
     * is configured with.
     *
     * @param  Closure(): string  $encode
     */
    private function shortestFloats(Closure $encode): string
    {
        $previous = ini_set('serialize_precision', '-1');

        try {
            return $encode();
        } finally {
            if (is_string($previous)) {
                ini_set('serialize_precision', $previous);
            }
        }
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
