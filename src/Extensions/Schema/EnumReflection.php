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
 * Reflection over a PHP enum for schema mappers: the documentable case values (backing values for a
 * backed enum, case names otherwise) and any `#[CaseDescription]` prose keyed by that same value.
 * Reflecting an enum + its Docuccino attributes is framework-neutral, so it lives in core beside the
 * built-in {@see EnumSchema} mapper that drives it (the adapter's
 * Eloquent model mapper reads it too, for enum-cast columns). Totalising — a non-enum or reflection
 * failure yields empty results rather than throwing.
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
     * The case names of an enum, in declaration order — the {@see EnumT}
     * `cases` contract (distinct from backing {@see values()}).
     *
     * @return list<string>
     */
    public static function names(string $fqcn): array
    {
        return array_map(static fn (ReflectionEnumUnitCase $case): string => $case->getName(), self::cases($fqcn));
    }

    /**
     * `#[CaseDescription]` prose keyed by the same value {@see values()} emits — so the map lines up
     * with the schema's `enum` member for `x-enumDescriptions`. When a case carries no
     * `#[CaseDescription]`, its docblock SUMMARY (the first prose paragraph) is used instead
     * (the attribute always wins where both are present); a case with neither is omitted.
     *
     * The docblock summary is extracted with a lightweight first-paragraph reader (core stays free of
     * the phpdoc-parser dependency — this is plain comment-marker stripping, not tag parsing).
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
     * The first prose paragraph of a docblock (its summary), or null when there is none. Strips the
     * `/** *​/` markers and per-line `*`, stops at the first blank line or `@tag` line, and collapses
     * the paragraph to a single trimmed string — deterministic, no external parser.
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
     * The file the enum is declared in, or null when it is not reflectable (e.g. an internal enum).
     * A fragment-cache dependency: an enum-cast column's schema changes when a case is added/removed.
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
