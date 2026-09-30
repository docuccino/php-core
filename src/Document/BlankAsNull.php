<?php

declare(strict_types=1);

namespace Docuccino\Core\Document;

/**
 * The fact that the server reads a blank request string at a schema's position as null, and accepts it:
 * `x-docuccino.facts.blankAsNull`, whose value is the pattern a blank matches, read with code-point
 * semantics (ECMA-262's `u` flag). A fact rather than a keyword, because the schema is the contract a
 * client is held to and sending null already does what a blank does; the fact is for tooling that
 * checks what a real client sends, the contract assertions first. The writer and every reader meet here.
 *
 * @internal
 */
final class BlankAsNull
{
    public const string FACT = 'blankAsNull';

    /**
     * The schema stating the fact beside whatever `x-docuccino` it already carries.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function onto(array $schema, string $pattern): array
    {
        $extension = is_array($schema['x-docuccino'] ?? null) ? $schema['x-docuccino'] : [];
        $facts = is_array($extension['facts'] ?? null) ? $extension['facts'] : [];
        $facts[self::FACT] = $pattern;
        $extension['facts'] = $facts;
        $schema['x-docuccino'] = $extension;

        return $schema;
    }

    /**
     * The pattern a schema node states, or null where it states none.
     *
     * @param  array<array-key, mixed>  $node
     */
    public static function of(array $node): ?string
    {
        $extension = $node['x-docuccino'] ?? null;
        $facts = is_array($extension) ? ($extension['facts'] ?? null) : null;
        $pattern = is_array($facts) ? ($facts[self::FACT] ?? null) : null;

        return is_string($pattern) ? $pattern : null;
    }

    /** Whether `$value` is a blank the pattern names. A pattern PCRE will not compile names none. */
    public static function matches(string $pattern, string $value): bool
    {
        if (str_contains($pattern, "\x01")) {
            return false;
        }

        return @preg_match("\x01".$pattern."\x01u", $value) === 1;
    }
}
