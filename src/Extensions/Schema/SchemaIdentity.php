<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Schema;

use Docuccino\Attributes\Hidden;
use Docuccino\Attributes\SchemaId;
use Docuccino\Attributes\SchemaName;
use ReflectionClass;

/**
 * Reads the Docuccino `#[SchemaName]` (component display name), `#[SchemaId]` (diff identity) and the
 * class-level `#[Hidden]` deny-list off a class — the reading every schema mapper that hoists a class
 * to a component honours, so it stays identical whether the source is core's built-in class mapper, a
 * spatie Data class, an API Resource, or an Eloquent model. Reflecting Docuccino attributes off a
 * class is framework-neutral, so it lives beside {@see ComponentHoist} in core.
 */
final class SchemaIdentity
{
    /**
     * The property names a class-level `#[Hidden(...)]` deny-lists from output (merged across every
     * such attribute), or `[]` when the class carries none.
     *
     * @return list<string>
     */
    public static function hidden(string $fqcn): array
    {
        if (! class_exists($fqcn)) {
            return [];
        }

        $hidden = [];
        foreach ((new ReflectionClass($fqcn))->getAttributes(Hidden::class) as $attribute) {
            $hidden = [...$hidden, ...$attribute->newInstance()->properties];
        }

        return $hidden;
    }

    /** The `#[SchemaName]` display name, else null (caller defaults to the short class name). */
    public static function name(string $fqcn): ?string
    {
        if (! class_exists($fqcn)) {
            return null;
        }

        foreach ((new ReflectionClass($fqcn))->getAttributes(SchemaName::class) as $attribute) {
            return $attribute->newInstance()->name;
        }

        return null;
    }

    /** The `#[SchemaId]` identity, else null (caller defaults to the FQCN). */
    public static function id(string $fqcn): ?string
    {
        if (! class_exists($fqcn)) {
            return null;
        }

        foreach ((new ReflectionClass($fqcn))->getAttributes(SchemaId::class) as $attribute) {
            return $attribute->newInstance()->id;
        }

        return null;
    }
}
