<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Schema;

use Docuccino\Attributes\CaseDescription;
use Docuccino\Core\Extensions\BuiltIn\EnumSchema;
use Docuccino\Core\Inference\DType\EnumT;
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
     * @return array<string, string>
     */
    public static function descriptions(string $fqcn): array
    {
        $out = [];
        foreach (self::cases($fqcn) as $case) {
            $description = self::caseDescription($case);
            if ($description === null) {
                continue;
            }

            $out[(string) self::caseValue($case)] = $description;
        }

        return $out;
    }

    private static function caseDescription(ReflectionEnumUnitCase $case): ?string
    {
        $attributes = $case->getAttributes(CaseDescription::class);
        if ($attributes !== []) {
            try {
                return $attributes[0]->newInstance()->description;
            } catch (Throwable) {
                return null;
            }
        }

        return self::docSummary($case->getDocComment());
    }

    /**
     * The first prose paragraph of a docblock, or null when there is none: strip the markers and
     * per-line `*`, stop at the first blank or `@tag` line, collapse to one trimmed string. Hand-rolled
     * so core stays free of the phpdoc-parser dependency — marker stripping, not tag parsing.
     */
    private static function docSummary(string|false $doc): ?string
    {
        if ($doc === false) {
            return null;
        }

        $body = preg_replace('#^\s*/\*\*+|\*+/\s*$#', '', $doc) ?? '';
        $paragraph = [];
        foreach (preg_split('/\r?\n/', $body) ?: [] as $line) {
            $line = trim(ltrim(trim($line), '*'));

            if ($line === '' || str_starts_with($line, '@')) {
                if ($paragraph !== []) {
                    break;
                }

                continue;
            }

            $paragraph[] = $line;
        }

        $summary = trim(implode(' ', $paragraph));

        return $summary === '' ? null : $summary;
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
