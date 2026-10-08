<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Schema;

use Docuccino\Core\Extensions\BuiltIn\ClassTypeToSchema;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\PropertyMetadata;

/**
 * Whether a class publishes a different shape to a client SENDING it than to one reading it — its own
 * request component. A plain class differs where a key is required on one side only; any class differs
 * where something it reaches does, a seal's member or a property's class, because a body referencing a
 * request component is itself a request shape. One identity holding both would publish whichever side
 * met it first, and an unrelated route would move the other.
 *
 * @internal
 */
final class RequestShape
{
    /**
     * Whether a plain object's key is always there. A response carries what is initialised, nullable or
     * not — so a property the constructor assigns on only some paths is optional; a request may leave out
     * what a default fills in ({@see PropertyPresence}). Where neither can be proved, a nullable type
     * stands in for "may be absent".
     */
    public static function required(string $fqcn, PropertyMetadata $property, bool $request): bool
    {
        $nullable = $property->type instanceof UnionT && $property->type->containsNull();

        return $request
            ? ! $nullable && ! PropertyPresence::defaulted($fqcn, $property->name)
            : PropertyPresence::written($fqcn, $property->name, $property->initialised) ?? ! $nullable;
    }

    /** Whether any published key of a plain object is required on one side of the wire and not the other. */
    public static function keysDiffer(string $fqcn, SchemaContext $context): bool
    {
        foreach (self::published($fqcn, $context) as $property) {
            if (self::required($fqcn, $property, true) !== self::required($fqcn, $property, false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether anything the class reaches publishes a request shape of its own. Every class weighed is a
     * dependency of the answer, since editing any of them can flip it.
     */
    public static function reached(string $fqcn, SchemaContext $context): bool
    {
        $seen = [$fqcn => true];

        return self::reachedFrom($fqcn, $context, $seen);
    }

    /** @param  array<string, true>  $seen */
    private static function differs(string $fqcn, SchemaContext $context, array &$seen): bool
    {
        if (isset($seen[$fqcn])) {
            return false;
        }
        $seen[$fqcn] = true;

        return (SealedHierarchy::of($fqcn) === null && self::publishedPlain($fqcn, $context) && self::keysDiffer($fqcn, $context))
            || self::reachedFrom($fqcn, $context, $seen);
    }

    /**
     * Whether {@see ClassTypeToSchema} is the mapper that publishes the class, so its key rule is the one the
     * class is published by. Another mapper — a Data class's, a resource's — publishes one shape to both sides.
     */
    private static function publishedPlain(string $fqcn, SchemaContext $context): bool
    {
        return ! $context instanceof SchemaConverter || $context->mapperFor(new ClassT($fqcn)) instanceof ClassTypeToSchema;
    }

    /** @param  array<string, true>  $seen */
    private static function reachedFrom(string $fqcn, SchemaContext $context, array &$seen): bool
    {
        $context->dependsOn(...DeclarationFiles::of($fqcn));

        $sealed = SealedHierarchy::of($fqcn);
        $types = $sealed !== null
            ? ($sealed->unreadable === [] ? $sealed->members : [])
            : array_map(static fn (PropertyMetadata $property) => $property->type, self::published($fqcn, $context));

        foreach ($types as $type) {
            // Read off the serialised form, so every container — list, map, shape, union, a generic's
            // arguments — is walked without this naming them.
            foreach (self::classesIn($type->toArray()) as $class) {
                if (self::differs($class, $context, $seen)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The properties a plain object publishes, less what `#[Hidden]` denies.
     *
     * @return list<PropertyMetadata>
     */
    private static function published(string $fqcn, SchemaContext $context): array
    {
        $metadata = $context->engine()->classMetadata(new ClassRef($fqcn));
        $context->dependsOn(...$metadata->dependencyFiles);

        $hidden = SchemaIdentity::hidden($fqcn);

        return array_values(array_filter(
            $metadata->properties,
            static fn (PropertyMetadata $property): bool => ! in_array($property->name, $hidden, true) && ! SchemaIdentity::hidesProperty($fqcn, $property->name),
        ));
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @return list<string>
     */
    private static function classesIn(array $node): array
    {
        $classes = ($node['kind'] ?? null) === ClassT::KIND && is_string($node['fqcn'] ?? null) ? [$node['fqcn']] : [];
        foreach ($node as $child) {
            if (is_array($child)) {
                $classes = [...$classes, ...self::classesIn($child)];
            }
        }

        return $classes;
    }
}
