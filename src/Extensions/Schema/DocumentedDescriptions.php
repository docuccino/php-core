<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Schema;

use Docuccino\Core\Inference\PropertyMetadata;

/**
 * Writes each property's docblock summary onto the member it publishes as that member's `description`.
 *
 * The docblock LAYER of a property's prose, and the sibling of {@see DocumentedExamples}: both are
 * precedence 30, both are overwritten a moment later by {@see PropertyAnnotations} at 40, so callers
 * run this BEFORE that reader. A class-hoisting mapper writes the same fact inline while it builds each
 * property; this is the form a schema already assembled needs — a request body recovered from
 * validation rules names its fields from rules rather than from properties, so the prose has to be
 * matched back onto it afterwards.
 *
 * A summary is text the docblock already parsed, so unlike an `@example` there is nothing here that can
 * fail to read as the type the schema states, and so nothing to report.
 */
final class DocumentedDescriptions
{
    /**
     * `$object` with every property summary written to the member it publishes. `$keys` maps a PHP
     * property name to the key it publishes under, for a shape whose wire names differ from its
     * properties.
     *
     * A property the object doesn't publish is skipped whole: a field the rules never mentioned, or one
     * a `#[Hidden]` dropped, legitimately carries prose nobody reads here.
     *
     * @param  array<string, mixed>  $object
     * @param  list<PropertyMetadata>  $properties
     * @param  array<string, string>  $keys
     * @return array<string, mixed>
     */
    public static function applyTo(array $object, array $properties, array $keys = []): array
    {
        $published = is_array($object['properties'] ?? null) ? $object['properties'] : [];
        if ($published === []) {
            return $object;
        }

        foreach ($properties as $property) {
            if ($property->summary === null) {
                continue;
            }

            $key = $keys[$property->name] ?? $property->name;
            if (! is_array($published[$key] ?? null)) {
                continue;
            }

            /** @var array<string, mixed> $schema */
            $schema = $published[$key];
            $schema['description'] = $property->summary;
            $published[$key] = $schema;
        }

        $object['properties'] = $published;

        return $object;
    }
}
