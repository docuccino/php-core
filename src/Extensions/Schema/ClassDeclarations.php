<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Schema;

use ReflectionClass;
use Throwable;

/**
 * Reads the attribute declarations a class writes about ITSELF, instantiated — the one reader the
 * class-level attributes go through, so the policy below is stated once instead of once per attribute.
 *
 * The class's OWN declarations only: PHP does not inherit class attributes, and a base DTO's
 * declaration describes the base, so carrying it down would put one statement on every shape under it.
 * And a declaration whose constructor rejects its arguments says nothing — that is the adapter's
 * `attribute.unreadable` story on an action, and a type has no route bag that collected it, so there is
 * nothing here to name it with.
 *
 * Which is why a reader whose silence would PUBLISH something does not come through here:
 * {@see SchemaIdentity} instantiates its own, so a `#[Hidden]` PHP cannot construct never quietly
 * publishes the property it was written to keep out, and a `#[SchemaId]` never quietly falls back to an
 * identity a diff reads as a different schema.
 */
final class ClassDeclarations
{
    /**
     * The class's own `$attribute` declarations, in source order — none for a class that does not exist.
     *
     * @template T of object
     *
     * @param  class-string<T>  $attribute
     * @return list<T>
     */
    public static function of(string $fqcn, string $attribute): array
    {
        if (! class_exists($fqcn)) {
            return [];
        }

        $declarations = [];
        foreach ((new ReflectionClass($fqcn))->getAttributes($attribute) as $declaration) {
            try {
                $declarations[] = $declaration->newInstance();
            } catch (Throwable) {
                // Says nothing: see the class header for why there is nothing to report it against.
            }
        }

        return $declarations;
    }
}
