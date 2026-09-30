<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Schema;

use Docuccino\Attributes\CaseDescription;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\UnreadableAttribute;
use Docuccino\Core\Extensions\BuiltIn\EnumSchema;
use Docuccino\Core\Inference\DType\EnumT;
use Docuccino\Core\Provenance\ClassNames;
use ReflectionEnum;
use ReflectionEnumBackedCase;
use ReflectionEnumUnitCase;
use Throwable;

/**
 * Reflection over a PHP enum for schema mappers: documentable case values (backing values for a
 * backed enum, case names otherwise) and `#[CaseDescription]` prose keyed by that same value. Lives
 * in core beside the {@see EnumSchema} mapper that drives it; the adapter's Eloquent mapper reads it
 * too, for enum-cast columns. Totalising — a non-enum or a reflection failure yields empty results
 * rather than throwing.
 */
final class EnumReflection
{
    /**
     * The case values a backed enum exposes (its backing values) or, for a pure enum, its case
     * names — in declaration order.
     *
     * @return list<string|int>
     */
    public static function values(string $fqcn): array
    {
        return array_map(self::caseValue(...), self::cases($fqcn));
    }

    /**
     * The case names, in declaration order — the {@see EnumT} `cases` contract, distinct from the
     * backing {@see values()}.
     *
     * @return list<string>
     */
    public static function names(string $fqcn): array
    {
        return array_map(static fn (ReflectionEnumUnitCase $case): string => $case->getName(), self::cases($fqcn));
    }

    /**
     * `#[CaseDescription]` prose keyed by the same value {@see values()} emits, so the map lines up
     * with the schema's `enum` member for `x-enumDescriptions`. A case with no attribute falls back to
     * its docblock summary (the attribute wins where both exist); a case with neither is omitted.
     *
     * A `#[CaseDescription]` PHP cannot construct is read as absent — the docblock answers — and its
     * report handed back beside the map, so a caller publishing the prose cannot drop it by not asking.
     *
     * @return array{0: array<string, string>, 1: list<Diagnostic>}
     */
    public static function descriptions(string $fqcn): array
    {
        $out = [];
        $diagnostics = [];
        foreach (self::cases($fqcn) as $case) {
            [$description, $unreadable] = self::caseDescription($case, ClassNames::publishable($fqcn).'::'.$case->getName());
            array_push($diagnostics, ...$unreadable);
            if ($description === null) {
                continue;
            }

            $out[(string) self::caseValue($case)] = $description;
        }

        return [$out, $diagnostics];
    }

    /**
     * @return array{0: ?string, 1: list<Diagnostic>}
     */
    private static function caseDescription(ReflectionEnumUnitCase $case, string $site): array
    {
        // The first only: the attribute does not repeat.
        [$declared, $diagnostics] = UnreadableAttribute::instantiate(array_slice($case->getAttributes(CaseDescription::class), 0, 1), $site);

        return [$declared !== [] ? $declared[0]->description : DocSummary::of($case->getDocComment()), $diagnostics];
    }

    /**
     * The file the enum is declared in, or null when it isn't reflectable (an internal enum, say). A
     * fragment-cache dependency: adding or removing a case changes an enum-cast column's schema.
     */
    public static function file(string $fqcn): ?string
    {
        if (! enum_exists($fqcn)) {
            return null;
        }

        try {
            $file = (new ReflectionEnum($fqcn))->getFileName();
        } catch (Throwable) {
            return null;
        }

        return $file !== false ? $file : null;
    }

    /**
     * @return list<ReflectionEnumUnitCase>
     */
    private static function cases(string $fqcn): array
    {
        if (! enum_exists($fqcn)) {
            return [];
        }

        try {
            return array_values((new ReflectionEnum($fqcn))->getCases());
        } catch (Throwable) {
            return [];
        }
    }

    private static function caseValue(ReflectionEnumUnitCase $case): string|int
    {
        if ($case instanceof ReflectionEnumBackedCase) {
            $value = $case->getBackingValue();

            return is_int($value) ? $value : (string) $value;
        }

        return $case->getName();
    }
}
