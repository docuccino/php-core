<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Schema;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Support\JsonValue;
use JsonException;
use stdClass;

/**
 * The one reading of an authored example LITERAL against the schema type it will sit beside.
 *
 * A docblock `@example` and a `#[RuleSchema]` example rule both arrive as text, because text is all a
 * docblock tag or a rule parameter can hold — so `@example false` on a `bool` property reaches a
 * producer as the string `"false"`, and writing it straight through publishes an example that
 * contradicts the `type` beside it. An example is the part of a document a consumer copies, so a wrong
 * one is not vague, it is false: this reads the text as the declared type or answers null, and a caller
 * that gets null publishes nothing and raises {@see self::untypable()} instead. Every producer that
 * turns authored text into an example goes through here, or the document carries two readings of one
 * literal and only one of them can be right.
 *
 * The reading is a function of the type SET, never of the order its members were met — a union is tried
 * most specific first, so `integer|null` reads `7` as a number and `null` as null. Where the schema
 * states no type of its own the text stands exactly as written: a `$ref` or an `anyOf` may well accept
 * it, nothing here can tell, and the example audit that runs over the finished document is what holds
 * that case to its schema.
 *
 * An object literal is classified off an OBJECT-decode and then read by {@see JsonValue}, the reader the
 * `#[Example(file:)]` and recorded-body paths also go through — one literal, one reading, however it
 * reached the build.
 */
final class TypedExample
{
    /** Most specific first, so a union's reading is a function of which types are in it. */
    private const array ORDER = ['null', 'boolean', 'integer', 'number', 'array', 'object', 'string'];

    /**
     * The literal as the declared type, wrapped so a legitimate `false`, `0` or `null` is
     * distinguishable from "does not read as this type"; null when nothing in `$type` reads it.
     *
     * `$type` is a schema's own `type` keyword — one name, a list of them (OAS 3.1 nullability), or
     * absent. An absent or unrecognised type constrains nothing a string would violate, so there the
     * text stands as written.
     *
     * @return array{mixed}|null
     */
    public static function of(string $text, mixed $type): ?array
    {
        $types = self::types($type);
        if ($types === []) {
            return [$text];
        }

        foreach (self::ORDER as $candidate) {
            if (! in_array($candidate, $types, true)) {
                continue;
            }

            // A `string` type publishes the author's text byte for byte; every other reading works off
            // the trimmed literal, since whitespace around a docblock tag's value is layout.
            $read = $candidate === 'string' ? [$text] : self::read(trim($text), $candidate);
            if ($read !== null) {
                return $read;
            }
        }

        return null;
    }

    /**
     * The one wording for "this example was not published", so the news reads the same whichever
     * producer had the literal in hand.
     *
     * @param  string  $subject  what carried it, as a reader would name it: `App\Data\TeamData::$sso_required`
     */
    public static function untypable(string $subject, string $text, mixed $type): Diagnostic
    {
        $declared = self::types($type);

        return new Diagnostic(
            severity: Severity::Warning,
            code: 'docblock.example-untypable',
            message: sprintf(
                'The example on %s ("%s") does not read as %s, so none is published.',
                $subject,
                $text,
                $declared === [] ? 'a value this schema can carry' : implode('/', $declared),
            ),
            help: 'Write the example as the value the property really is — `@example false` for a boolean, '
                .'`@example 7` for an integer, a JSON literal for an array or object. An example of the wrong '
                .'type gets copied out of the document and rejected by your own API, so it is dropped rather '
                .'than published.',
        );
    }

    /** @return array{mixed}|null */
    private static function read(string $text, string $type): ?array
    {
        if ($text === '') {
            return null;
        }

        return match ($type) {
            'null' => $text === 'null' ? [null] : null,
            'boolean' => self::boolean($text),
            'integer' => self::integer($text),
            'number' => self::number($text),
            'array' => self::json($text, wantList: true),
            'object' => self::json($text, wantList: false),
            default => null,
        };
    }

    /** @return array{bool}|null */
    private static function boolean(string $text): ?array
    {
        $read = filter_var($text, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $read === null ? null : [$read];
    }

    /** @return array{int}|null */
    private static function integer(string $text): ?array
    {
        $read = filter_var($text, FILTER_VALIDATE_INT);

        return $read === false ? null : [$read];
    }

    /** @return array{float}|null */
    private static function number(string $text): ?array
    {
        $read = filter_var($text, FILTER_VALIDATE_FLOAT);

        return $read === false ? null : [$read];
    }

    /**
     * A JSON literal, classified and published off ONE object-decode — the only decode that tells a list
     * from a map, so re-decoding associatively to publish would throw that reading away again.
     *
     * @return array{mixed}|null
     */
    private static function json(string $text, bool $wantList): ?array
    {
        try {
            /** @var mixed $shape */
            $shape = json_decode($text, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if ($wantList ? ! is_array($shape) : ! $shape instanceof stdClass) {
            return null;
        }

        return [JsonValue::normalize($shape)];
    }

    /**
     * The `type` keyword as a list of names this reader knows, deduplicated, with anything it does not
     * know dropped.
     *
     * @return list<string>
     */
    private static function types(mixed $type): array
    {
        /** @var list<mixed> $members */
        $members = is_array($type) ? array_values($type) : [$type];
        $out = [];

        foreach ($members as $member) {
            if (is_string($member) && in_array($member, self::ORDER, true) && ! in_array($member, $out, true)) {
                $out[] = $member;
            }
        }

        return $out;
    }
}
