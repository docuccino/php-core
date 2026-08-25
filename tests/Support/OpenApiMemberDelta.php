<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Support;

use RuntimeException;

/**
 * Which members OpenAPI 3.2 added to each object, read out of the two vendored meta-schemas rather than
 * listed by hand. This is the source of truth a downlevel guard has to couple to: the emitter's own table
 * of members to drop cannot prove itself complete, and the defect this exists to catch is a member the
 * spec added that nobody noticed.
 *
 * A member counts as declared wherever the object's schema can reach it — `properties` directly, and
 * through `allOf`/`anyOf`/`oneOf`, `if`/`then`/`else`, `dependentSchemas` and `$ref`. Two of those are
 * load-bearing rather than thorough. `dependentSchemas`: 3.1 declares a Header Object's `example` and
 * `examples` only under `dependentSchemas.schema`, so skipping it reports two members as 3.2 additions
 * that 3.1 has had all along. And a `$ref` is followed by resolving its WHOLE pointer, because 3.1 keeps
 * a parameter's per-style members in `$defs` nested inside the parameter's own subschema — a reader that
 * only understands a top-level `#/$defs/<name>` misses them and reports `allowReserved` the same way.
 */
final class OpenApiMemberDelta
{
    /** A `$defs` name ending this way is `anyOf[the object, a Reference]`, so its delta is the object's. */
    private const string REFERENCE_VARIANT = '-or-reference';

    /**
     * Every member 3.2 declares that 3.1 does not, keyed by the `$defs` name of the object carrying it.
     * Objects with no addition are absent.
     *
     * @return array<string, list<string>>
     */
    public static function added32(): array
    {
        $root31 = self::root('openapi-3.1');
        $root32 = self::root('openapi-3.2');

        /** @var array<string, mixed> $defs31 */
        $defs31 = $root31['$defs'];
        /** @var array<string, mixed> $defs32 */
        $defs32 = $root32['$defs'];

        $added = [];

        foreach (array_keys($defs32) as $object) {
            $object = (string) $object;

            if (! isset($defs31[$object]) || str_ends_with($object, self::REFERENCE_VARIANT)) {
                continue;
            }

            $new = array_values(array_diff(
                self::members($defs32[$object], $root32),
                self::members($defs31[$object], $root31),
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
     * Every member name one object's schema can declare.
     *
     * @param  array<string, mixed>  $root
     * @param  list<string>  $seen  the pointers already followed, so a self-referential schema ends
     * @return list<string>
     */
    private static function members(mixed $schema, array $root, array $seen = []): array
    {
        if (! is_array($schema)) {
            return [];
        }

        $names = is_array($schema['properties'] ?? null) ? array_keys($schema['properties']) : [];

        foreach (['allOf', 'anyOf', 'oneOf'] as $branches) {
            foreach (is_array($schema[$branches] ?? null) ? $schema[$branches] : [] as $branch) {
                $names = [...$names, ...self::members($branch, $root, $seen)];
            }
        }

        foreach (['if', 'then', 'else'] as $conditional) {
            $names = [...$names, ...self::members($schema[$conditional] ?? null, $root, $seen)];
        }

        foreach (is_array($schema['dependentSchemas'] ?? null) ? $schema['dependentSchemas'] : [] as $dependent) {
            $names = [...$names, ...self::members($dependent, $root, $seen)];
        }

        $ref = $schema['$ref'] ?? null;

        if (is_string($ref) && str_starts_with($ref, '#/') && ! in_array($ref, $seen, true)) {
            $names = [...$names, ...self::members(self::resolve($ref, $root), $root, [...$seen, $ref])];
        }

        return array_values(array_unique(array_map(strval(...), $names)));
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
