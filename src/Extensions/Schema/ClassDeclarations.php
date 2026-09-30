<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Schema;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\UnreadableAttribute;
use Docuccino\Core\Provenance\ClassNames;
use ReflectionClass;

/**
 * Reads the attribute declarations a class writes about ITSELF, instantiated — the one reader the
 * class-level attributes go through, so the policy below is stated once instead of once per attribute.
 *
 * The class's OWN declarations only: PHP does not inherit class attributes, and a base DTO's
 * declaration describes the base, so carrying it down would put one statement on every shape under it.
 * A declaration whose constructor rejects its arguments is read as absent and handed back beside the
 * rest as `attribute.unreadable` ({@see UnreadableAttribute}). The reader that APPLIES the declarations
 * reports it; one only asking whether any exist discards it where it says so, so one mistake is one report.
 *
 * Which is why a reader whose silence would PUBLISH something does not come through here:
 * {@see SchemaIdentity} instantiates its own, so a `#[Hidden]` PHP cannot construct never quietly
 * publishes the property it was written to keep out, and a `#[SchemaId]` never quietly falls back to an
 * identity a diff reads as a different schema.
 */
final class ClassDeclarations
{
    /**
     * The class's own `$attribute` declarations, in source order — none for a class that does not exist —
     * and the reports for those PHP could not construct.
     *
     * @template T of object
     *
     * @param  class-string<T>  $attribute
     * @return array{0: list<T>, 1: list<Diagnostic>}
     */
    public static function of(string $fqcn, string $attribute): array
    {
        if (! class_exists($fqcn)) {
            return [[], []];
        }

        return UnreadableAttribute::instantiate((new ReflectionClass($fqcn))->getAttributes($attribute), ClassNames::publishable($fqcn));
    }
}
