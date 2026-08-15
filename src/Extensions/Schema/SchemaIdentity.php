<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Schema;

use Docuccino\Attributes\Hidden;
use Docuccino\Attributes\SchemaId;
use Docuccino\Attributes\SchemaName;
use ReflectionClass;

/**
 * Reads `#[SchemaName]` (component display name), `#[SchemaId]` (diff identity) and the `#[Hidden]`
 * deny-list — class-level names and the per-property form — off a class. Every class-hoisting schema
 * mapper reads them through here, so the behaviour is identical whether the source is core's class
 * mapper, a spatie Data class, an API Resource or an Eloquent model.
 */
final class SchemaIdentity
{
    /**
     * Property names a class-level `#[Hidden(...)]` keeps out of the output, merged across every such
     * attribute.
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

    /** Whether the property carries its own `#[Hidden]`, the per-property half of the same deny-list. */
    public static function hidesProperty(string $fqcn, string $property): bool
    {
        if (! class_exists($fqcn)) {
            return false;
        }

        $reflection = new ReflectionClass($fqcn);

        return $reflection->hasProperty($property)
            && $reflection->getProperty($property)->getAttributes(Hidden::class) !== [];
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
