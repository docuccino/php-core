<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Schema;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Provenance\ClassNames;

/**
 * Writes each property's docblock `@example` onto the member it publishes, typed through
 * {@see TypedExample} against the schema that member already carries.
 *
 * The docblock LAYER of a property declaration, and the mirror of the `description` a mapper writes
 * from {@see PropertyMetadata::$summary}: both are precedence 30, both are overwritten a moment later
 * by {@see PropertyAnnotations} at precedence 40, so an author who writes an attribute and a docblock
 * on one property gets the attribute. Callers therefore run this BEFORE that reader, never after.
 *
 * A docblock tag holds text and nothing else, so `@example false` on a `bool` arrives as the string
 * `"false"` and has to be read as the type the schema states. That reading is {@see TypedExample}'s,
 * whole — a literal that does not read as its declared type publishes nothing and says so, because an
 * example is the part of a document a consumer copies into a client.
 *
 * Only a NATIVE property carries one: a magic `@property` tag has no docblock of its own for a tag to
 * sit in, so a model's magic columns reach here with nothing to publish, exactly as they reach
 * {@see PropertyAnnotations} with no attribute to read.
 */
final class DocumentedExamples
{
    /**
     * `$object` with every publishable `@example` written to its member, the untypable ones reported.
     * `$keys` maps a PHP property name to the key it publishes under, for a mapper whose wire names
     * differ from its properties.
     *
     * A property the object does not publish is skipped whole — a hidden one legitimately carries an
     * example nobody reads here, and reporting that would fire where there is nothing to do.
     *
     * @param  array<string, mixed>  $object
     * @param  list<PropertyMetadata>  $properties
     * @param  array<string, string>  $keys
     * @return array<string, mixed>
     */
    public static function applyTo(SchemaContext $context, array $object, string $fqcn, array $properties, array $keys = []): array
    {
        $published = is_array($object['properties'] ?? null) ? $object['properties'] : [];
        if ($published === []) {
            return $object;
        }

        // The name a report names the property's class by — never the raw `class-string` the caller was
        // handed, which for an ANONYMOUS class carries the build machine and a per-process counter
        // ({@see ClassNames}).
        $site = ClassNames::publishable($fqcn);

        foreach ($properties as $property) {
            if ($property->example === null) {
                continue;
            }

            $key = $keys[$property->name] ?? $property->name;
            if (! is_array($published[$key] ?? null)) {
                continue;
            }

            /** @var array<string, mixed> $schema */
            $schema = $published[$key];

            $example = TypedExample::of($property->example, $schema['type'] ?? null);
            if ($example === null) {
                $context->diagnostic(TypedExample::untypable(
                    $site.'::$'.$property->name,
                    $property->example,
                    $schema['type'] ?? null,
                ));

                continue;
            }

            $schema['example'] = $example[0];
            $published[$key] = $schema;
        }

        $object['properties'] = $published;

        return $object;
    }
}
