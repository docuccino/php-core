<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Support;

use RuntimeException;

/**
 * What each vendored meta-schema declares about each object, read out of the files rather than listed by
 * hand, and which members OpenAPI 3.2 added on top of 3.1. This is the source of truth a downlevel guard
 * has to couple to: the emitter's own table of members to drop cannot prove itself complete, and the
 * defect this exists to catch is a member the spec added that nobody noticed.
 *
 * A member counts as declared wherever the object's schema can reach it — `properties` directly, and
 * through `allOf`/`anyOf`/`oneOf`, `if`/`then`/`else`, `dependentSchemas` and `$ref`. Two of those are
 * load-bearing rather than thorough. `dependentSchemas`: 3.1 declares a Header Object's `example` and
 * `examples` only under `dependentSchemas.schema`, so skipping it reports two members as 3.2 additions
 * that 3.1 has had all along. And a `$ref` is followed by resolving its WHOLE pointer, because 3.1 keeps
 * a parameter's per-style members in `$defs` nested inside the parameter's own subschema — a reader that
 * only understands a top-level `#/$defs/<name>` misses them and reports `allowReserved` the same way.
 *
 * {@see declarations()} carries the VALUE domain declared for each member beside the member itself, and
 * {@see OpenApiValueDelta} is the second axis read off it: one reader for both, so the member axis and the
 * value axis can never disagree about what a version's schema reaches.
 */
final class OpenApiMemberDelta
{
    /** A `$defs` name ending this way is `anyOf[the object, a Reference]`, so its delta is the object's. */
    private const string REFERENCE_VARIANT = '-or-reference';

    /** @var array<string, array<string, array<string, list<string>>>> */
    private static array $declarations = [];

    /**
     * Every member 3.2 declares that 3.1 does not, keyed by the `$defs` name of the object carrying it.
     * Objects with no addition are absent.
     *
     * @return array<string, list<string>>
     */
    public static function added32(): array
    {
        $declared32 = self::declarations('openapi-3.2');
        $declared31 = self::declarations('openapi-3.1');

        $added = [];

        foreach (self::comparableObjects() as $object) {
            $new = array_values(array_diff(
                array_keys($declared32[$object]),
                array_keys($declared31[$object]),
            ));

            sort($new);

            if ($new !== []) {
                $added[$object] = $new;
            }
        }

        ksort($added);

        return $added;
    }

    /**
     * The `$defs` objects both meta-schemas define, which are the only ones a delta can be taken over.
     *
     * @return list<string>
     */
    public static function comparableObjects(): array
    {
        $objects = array_keys(array_intersect_key(
            self::declarations('openapi-3.2'),
            self::declarations('openapi-3.1'),
        ));

        $objects = array_values(array_filter(
            array_map(strval(...), $objects),
            static fn (string $object): bool => ! str_ends_with($object, self::REFERENCE_VARIANT),
        ));

        sort($objects);

        return $objects;
    }

    /**
     * Every member each of one version's objects can declare, with the value domain declared for it —
     * `enum` entries and a `const`, unioned over every position the object's schema reaches, and empty
     * where the member's domain is open. A non-string value is carried as its JSON encoding, so `true` and
     * `"true"` would collide; no position in the vendored files declares both.
     *
     * @return array<string, array<string, list<string>>>
     */
    public static function declarations(string $format): array
    {
        if (isset(self::$declarations[$format])) {
            return self::$declarations[$format];
        }

        $root = self::root($format);
        /** @var array<string, mixed> $defs */
        $defs = $root['$defs'];

        $declared = [];

        foreach ($defs as $object => $schema) {
            $declared[(string) $object] = self::declared($schema, $root);
        }

        ksort($declared);

        return self::$declarations[$format] = $declared;
    }

    /**
     * @return array<string, mixed>
     */
    private static function root(string $format): array
    {
        $file = OpenApiMetaSchema::SCHEMAS[$format]['file'] ?? throw new RuntimeException("No meta-schema for {$format}.");
        $decoded = json_decode((string) file_get_contents(dirname(__DIR__).'/Fixtures/'.$file), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded) || ! is_array($decoded['$defs'] ?? null)) {
            throw new RuntimeException("Meta-schema {$file} declares no \$defs.");
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Every member name one object's schema can declare, with the values declared for it.
     *
     * @param  array<string, mixed>  $root
     * @param  list<string>  $seen  the pointers already followed, so a self-referential schema ends
     * @return array<string, list<string>>
     */
    private static function declared(mixed $schema, array $root, array $seen = []): array
    {
        if (! is_array($schema)) {
            return [];
        }

        $found = [];

        foreach (is_array($schema['properties'] ?? null) ? $schema['properties'] : [] as $member => $subschema) {
            $found[(string) $member] = self::domain($subschema);
        }

        $branches = [];

        foreach (['allOf', 'anyOf', 'oneOf'] as $composition) {
            foreach (is_array($schema[$composition] ?? null) ? $schema[$composition] : [] as $branch) {
                $branches[] = $branch;
            }
        }

        foreach (['if', 'then', 'else'] as $conditional) {
            $branches[] = $schema[$conditional] ?? null;
        }

        foreach (is_array($schema['dependentSchemas'] ?? null) ? $schema['dependentSchemas'] : [] as $dependent) {
            $branches[] = $dependent;
        }

        foreach ($branches as $branch) {
            $found = self::merge($found, self::declared($branch, $root, $seen));
        }

        $ref = $schema['$ref'] ?? null;

        if (is_string($ref) && str_starts_with($ref, '#/') && ! in_array($ref, $seen, true)) {
            $found = self::merge($found, self::declared(self::resolve($ref, $root), $root, [...$seen, $ref]));
        }

        return $found;
    }

    /**
     * The values one member's subschema pins: its `enum` entries and its `const`. Empty means the domain is
     * open, which is what most members have.
     *
     * @return list<string>
     */
    private static function domain(mixed $subschema): array
    {
        if (! is_array($subschema)) {
            return [];
        }

        $values = is_array($subschema['enum'] ?? null) ? $subschema['enum'] : [];

        if (array_key_exists('const', $subschema)) {
            $values[] = $subschema['const'];
        }

        return array_values(array_unique(array_map(
            static fn (mixed $value): string => is_string($value) ? $value : (string) json_encode($value),
            $values,
        )));
    }

    /**
     * @param  array<string, list<string>>  $into
     * @param  array<string, list<string>>  $from
     * @return array<string, list<string>>
     */
    private static function merge(array $into, array $from): array
    {
        foreach ($from as $member => $values) {
            $into[$member] = array_values(array_unique([...$into[$member] ?? [], ...$values]));
        }

        return $into;
    }

    /**
     * One same-document JSON Pointer, resolved against the meta-schema root.
     *
     * @param  array<string, mixed>  $root
     */
    private static function resolve(string $ref, array $root): mixed
    {
        $node = $root;

        foreach (explode('/', substr($ref, 2)) as $token) {
            $token = str_replace(['~1', '~0'], ['/', '~'], $token);

            if (! is_array($node) || ! array_key_exists($token, $node)) {
                return null;
            }

            $node = $node[$token];
        }

        return $node;
    }
}
