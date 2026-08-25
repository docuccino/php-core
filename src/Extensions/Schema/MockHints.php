<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Schema;

use Docuccino\Attributes\Mock;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Provenance\ClassNames;
use ReflectionClass;

/**
 * Reads `#[Mock]` off a class and its properties and writes what it says to `x-docuccino.mock` on the
 * schema each named member publishes. Every class-hoisting schema mapper goes through here, so a plain
 * DTO carries a hint exactly as a Data class, an API Resource or an Eloquent model does.
 *
 * A hint is metadata a mock server reads; nothing is ever evaluated here, so no generated value can
 * reach the document. The expression itself is opaque — whoever consumes the hint defines its grammar
 * — so only an empty one is rejected. A property's own `#[Mock]` beats a class-level one naming it,
 * the same way a more specific attribute wins anywhere else.
 *
 * @phpstan-type MockHint array{faker?: string, seedGroup?: string}
 */
final class MockHints
{
    /**
     * {@see apply()} with the diagnostics reported straight to the schema context — what a mapper
     * building a component wants.
     *
     * @param  array<string, mixed>  $object
     * @param  array<string, string>  $keys
     * @return array<string, mixed>
     */
    public static function applyTo(SchemaContext $context, array $object, string $fqcn, array $keys = []): array
    {
        [$object, $diagnostics] = self::apply($object, $fqcn, $keys);

        foreach ($diagnostics as $diagnostic) {
            $context->diagnostic($diagnostic);
        }

        return $object;
    }

    /**
     * `$object` with each declared hint attached to the property it names, plus everything the
     * declarations got wrong. `$keys` maps a PHP property name to the key it publishes under, for a
     * mapper whose wire names differ from its properties; a property missing from it publishes under
     * its own name.
     *
     * @param  array<string, mixed>  $object
     * @param  array<string, string>  $keys
     * @return array{0: array<string, mixed>, 1: list<Diagnostic>}
     */
    public static function apply(array $object, string $fqcn, array $keys = []): array
    {
        if (! class_exists($fqcn)) {
            return [$object, []];
        }

        $reflection = new ReflectionClass($fqcn);
        $diagnostics = [];

        // The name every diagnostic below names the class by — never the raw `class-string` the caller was
        // handed, which for an ANONYMOUS class carries the build machine and a per-process counter
        // ({@see ClassNames}).
        $site = ClassNames::publishable($fqcn);

        // Class-level first, so a property's own attribute overwrites the one that named it from afar.
        $hints = [];
        foreach ($reflection->getAttributes(Mock::class) as $attribute) {
            $mock = $attribute->newInstance();
            $property = self::text($mock->property);

            if ($property === null) {
                $diagnostics[] = self::invalid(sprintf('#[Mock] on class %s names no property', $site));

                continue;
            }

            $hint = self::hint($mock);
            if ($hint === []) {
                $diagnostics[] = self::invalid(sprintf('#[Mock(property: \'%s\')] on class %s carries no faker expression and no seed group', $property, $site));

                continue;
            }

            $hints[$property] = $hint;
        }

        $published = is_array($object['properties'] ?? null) ? $object['properties'] : [];

        foreach ($hints as $property => $hint) {
            if (! array_key_exists($property, $published)) {
                $diagnostics[] = new Diagnostic(
                    severity: Severity::Warning,
                    code: 'attribute.mock-unknown-property',
                    message: sprintf('#[Mock(property: \'%s\')] on class %s names a property the schema does not publish; the hint is dropped.', $property, $site),
                    help: 'Name a property the schema publishes, or drop the attribute — a hidden or unrecovered property has nothing to carry it.',
                );
            }
        }

        foreach ($reflection->getProperties() as $property) {
            $attributes = $property->getAttributes(Mock::class);
            if ($attributes === []) {
                continue;
            }

            $hint = [];
            foreach ($attributes as $attribute) {
                $mock = $attribute->newInstance();

                if (self::text($mock->property) !== null) {
                    $diagnostics[] = self::invalid(sprintf('#[Mock] on %s::$%s names a property, which only a class-level one needs', $site, $property->getName()));
                }

                $hint = [...$hint, ...self::hint($mock)];
            }

            if ($hint === []) {
                $diagnostics[] = self::invalid(sprintf('#[Mock] on %s::$%s carries no faker expression and no seed group', $site, $property->getName()));

                continue;
            }

            // A property that publishes under another name carries its hint to that name.
            $hints[$keys[$property->getName()] ?? $property->getName()] = $hint;
        }

        foreach ($hints as $property => $hint) {
            if (! is_array($published[$property] ?? null)) {
                continue;
            }

            /** @var array<string, mixed> $schema */
            $schema = $published[$property];
            $docuccino = is_array($schema['x-docuccino'] ?? null) ? $schema['x-docuccino'] : [];
            $docuccino['mock'] = $hint;
            $schema['x-docuccino'] = $docuccino;
            $published[$property] = $schema;
        }

        if ($published !== []) {
            $object['properties'] = $published;
        }

        return [$object, $diagnostics];
    }

    /**
     * The hint an attribute carries, empty when it says nothing worth publishing.
     *
     * @return MockHint
     */
    private static function hint(Mock $mock): array
    {
        $hint = [];

        $faker = self::text($mock->faker);
        if ($faker !== null) {
            $hint['faker'] = $faker;
        }

        $seedGroup = self::text($mock->seedGroup);
        if ($seedGroup !== null) {
            $hint['seedGroup'] = $seedGroup;
        }

        return $hint;
    }

    private static function text(?string $value): ?string
    {
        $trimmed = trim($value ?? '');

        return $trimmed === '' ? null : $trimmed;
    }

    private static function invalid(string $what): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: 'attribute.mock-invalid',
            message: $what.'; it is ignored.',
            help: 'Give it a faker expression, a seed group, or both — and on a class, the name of the property it applies to.',
        );
    }
}
