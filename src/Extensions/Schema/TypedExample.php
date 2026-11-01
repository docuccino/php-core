<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Schema;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Support\JsonValue;
use JsonException;
use stdClass;

/**
 * The one reading of an authored example LITERAL against the schema it will sit beside.
 *
 * A docblock `@example` and a `#[RuleSchema]` example rule both arrive as text, because text is all a
 * docblock tag or a rule parameter can hold — so `@example false` on a `bool` property reaches a
 * producer as the string `"false"`, and writing it straight through publishes an example that
 * contradicts the `type` beside it. An example is the part of a document a consumer copies, so a wrong
 * one is not vague, it is false: this reads the text as a value the schema accepts or answers null, and a
 * caller that gets null publishes nothing and raises {@see self::unreadable()} instead. Every producer
 * that turns authored text into an example goes through here, or the document carries two readings of
 * one literal and only one of them can be right.
 *
 * An example is a value for the schema AS A WHOLE, so the reading is against every branch it admits —
 * through a `$ref` to the component it names, each `anyOf`/`oneOf` member, a type list, 3.0's
 * `nullable` — and a branch's `enum`/`const` is part of what accepts a reading. The readings are tried
 * most specific type first, and the first one some branch accepts wins: `null` beside a nullable enum
 * is the null, `7` beside an integer enum is the number, and `7` beside `integer|string` is the number
 * too. That makes the answer a function of the branches, never of the order they were written in.
 * Only where a branch states nothing this can read — a `$ref` to a component not registered yet, an
 * `allOf` of several — does the text stand as written, save a complete JSON string literal, which is
 * read as the string it quotes; the example audit over the finished document holds that case.
 *
 * An object literal is classified off an OBJECT-decode and then read by {@see JsonValue}, the reader the
 * `#[Example(file:)]` and recorded-body paths also go through — one literal, one reading, however it
 * reached the build.
 *
 * @phpstan-type Branch array{types: list<string>|null, values: list<mixed>|null}
 */
final class TypedExample
{
    /** Most specific first, so a union's reading is a function of which types are in it. */
    private const array ORDER = ['null', 'boolean', 'integer', 'number', 'array', 'object', 'string'];

    /** A branch stating nothing this can read against, which any text stands for. */
    private const array OPEN = ['types' => null, 'values' => null];

    /** Past this many values a refusal's message stops naming them. */
    private const int NAMED = 6;

    /**
     * The literal read against a bare `type` keyword — one name, a list of them, or absent — as
     * {@see against()} reads it against `{type: $type}`.
     *
     * @return array{mixed}|null
     */
    public static function of(string $text, mixed $type): ?array
    {
        return self::against($text, ['type' => $type]);
    }

    /**
     * The literal as a value `$schema` accepts, wrapped so a legitimate `false`, `0` or `null` is
     * distinguishable from "reads as nothing it accepts"; null when no reading is accepted.
     *
     * A `$ref` is read through `$schemas`, the `components.schemas` it names a member of; one naming
     * nothing there states nothing, and the text stands as written.
     *
     * @param  array<mixed>  $schema
     * @param  array<mixed>  $schemas
     * @return array{mixed}|null
     */
    public static function against(string $text, array $schema, array $schemas = []): ?array
    {
        $branches = self::branches($schema, $schemas);

        foreach (self::ORDER as $candidate) {
            $admitting = array_values(array_filter(
                $branches,
                static fn (array $branch): bool => $branch['types'] === null
                    ? $branch['values'] !== null
                    : in_array($candidate, $branch['types'], true),
            ));

            if ($admitting === []) {
                continue;
            }

            foreach (self::readings($text, $candidate) as $read) {
                foreach ($admitting as $branch) {
                    if ($branch['values'] === null || self::listed($read[0], $branch['values'])) {
                        return $read;
                    }
                }
            }
        }

        foreach ($branches as $branch) {
            if ($branch === self::OPEN) {
                return self::quoted($text) ?? [$text];
            }
        }

        return null;
    }

    /**
     * The one wording for "this example was not published", so the news reads the same whichever
     * producer had the literal in hand. `$type` is a bare `type` keyword, as {@see of()} takes it.
     *
     * @param  string  $subject  what carried it, as a reader would name it: `App\Data\TeamData::$sso_required`
     */
    public static function untypable(string $subject, string $text, mixed $type): Diagnostic
    {
        return self::unreadable($subject, $text, ['type' => $type]);
    }

    /**
     * {@see untypable()} for a literal read {@see against()} a whole schema, naming what that schema
     * accepts: its types, or the values it lists where it lists them.
     *
     * @param  array<mixed>  $schema
     * @param  array<mixed>  $schemas
     */
    public static function unreadable(string $subject, string $text, array $schema, array $schemas = []): Diagnostic
    {
        return self::refusal($subject, $text, self::accepted(self::branches($schema, $schemas)), 'none is published');
    }

    /**
     * {@see unreadable()} for a validation field, whose own rules earn an example where they pin a value,
     * so a refused one may leave a generated one in its place.
     *
     * @param  array<mixed>  $schema
     */
    public static function unreadableOnField(string $path, string $text, array $schema): Diagnostic
    {
        return self::refusal(
            'field "'.$path.'"',
            $text,
            self::accepted(self::branches($schema, [])),
            'it is not published; where the field\'s rules pin a value, an example generated from them is published instead',
        );
    }

    private static function refusal(string $subject, string $text, string $accepted, string $outcome): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: 'docblock.example-untypable',
            message: sprintf('The example on %s ("%s") does not read as %s, so %s.', $subject, $text, $accepted, $outcome),
            help: 'Write the example as the value the property really is — `@example false` for a boolean, '
                .'`@example 7` for an integer, a JSON literal for an array or object, and one of the listed '
                .'values where the schema lists them. An example the schema rejects gets copied out of the '
                .'document and rejected by your own API, so it is dropped rather than published.',
        );
    }

    /**
     * The string a text quotes, when the text is a complete JSON string literal (`"draft"` → `draft`);
     * null for anything else.
     *
     * Read where a string's bytes are refused — an enum of `["draft", …]` rejects `"draft"`, so the
     * quotes are the author writing a JSON literal, not two characters of the value — and where a branch
     * states nothing to read against. A string the schema leaves open keeps the author's bytes, quotes
     * included, because there nothing rules them out.
     *
     * @return array{string}|null
     */
    private static function quoted(string $text): ?array
    {
        $trimmed = trim($text);
        if (! str_starts_with($trimmed, '"') || ! str_ends_with($trimmed, '"') || strlen($trimmed) < 2) {
            return null;
        }

        try {
            /** @var mixed $read */
            $read = json_decode($trimmed, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_string($read) ? [$read] : null;
    }

    /**
     * Every branch `$schema` admits a value through. Each `$ref` is followed once, `$followed` marking it
     * true while it is read and false once it is: a component reached again while it is being read refers
     * to itself and ends as an open branch rather than a loop, and one already read adds nothing new, so
     * a component reached by many paths is read once rather than once per path.
     *
     * @param  array<mixed>  $schema
     * @param  array<mixed>  $schemas
     * @param  array<string, bool>  $followed
     * @return list<Branch>
     */
    private static function branches(array $schema, array $schemas, array &$followed = []): array
    {
        $nullable = ($schema['nullable'] ?? null) === true ? [['types' => ['null'], 'values' => null]] : [];

        $ref = $schema['$ref'] ?? null;
        if (is_string($ref)) {
            if (isset($followed[$ref])) {
                return [...($followed[$ref] ? [self::OPEN] : []), ...$nullable];
            }

            $body = DiscriminatedUnion::component($ref, $schemas);

            if ($body === null) {
                return [self::OPEN, ...$nullable];
            }

            $followed[$ref] = true;
            $branches = self::branches($body, $schemas, $followed);
            $followed[$ref] = false;

            return [...$branches, ...$nullable];
        }

        foreach (['anyOf', 'oneOf'] as $keyword) {
            $members = $schema[$keyword] ?? null;
            if (! is_array($members) || $members === []) {
                continue;
            }

            $branches = [];
            foreach ($members as $member) {
                $branches = [...$branches, ...(is_array($member) ? self::branches($member, $schemas, $followed) : [self::OPEN])];
            }

            return [...$branches, ...$nullable];
        }

        // 3.0's nullable reference is `allOf: [{$ref}]` beside `nullable: true`; an `allOf` of several is
        // an intersection this does not read.
        $allOf = $schema['allOf'] ?? null;
        if (is_array($allOf) && $allOf !== []) {
            $only = count($allOf) === 1 ? reset($allOf) : null;

            return [...(is_array($only) ? self::branches($only, $schemas, $followed) : [self::OPEN]), ...$nullable];
        }

        $types = self::types($schema['type'] ?? null);
        $values = match (true) {
            array_key_exists('const', $schema) => [$schema['const']],
            is_array($schema['enum'] ?? null) => array_values($schema['enum']),
            default => null,
        };

        if ($types === [] && $values === null) {
            return [self::OPEN, ...$nullable];
        }

        return [['types' => $types === [] ? null : $types, 'values' => $values], ...$nullable];
    }

    /**
     * The readings of the text as one type, in the order they are tried: a string's bytes first, then
     * the string a complete JSON string literal quotes.
     *
     * @return list<array{mixed}>
     */
    private static function readings(string $text, string $type): array
    {
        if ($type !== 'string') {
            // Whitespace around a docblock tag's value is layout, so every non-string reading is trimmed.
            $read = self::read(trim($text), $type);

            return $read === null ? [] : [$read];
        }

        $quoted = self::quoted($text);

        return $quoted === null ? [[$text]] : [[$text], $quoted];
    }

    /**
     * Whether a reading is one of the values a branch lists, compared as JSON compares them — `1` and
     * `1.0` are one number.
     *
     * @param  list<mixed>  $values
     */
    private static function listed(mixed $read, array $values): bool
    {
        $numeric = static fn (mixed $value): bool => is_int($value) || is_float($value);

        foreach ($values as $value) {
            if ($numeric($read) && $numeric($value) ? $read == $value : $read === $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * What a set of branches accepts, as a refusal names it: each branch's listed values, or its types.
     *
     * @param  list<Branch>  $branches
     */
    private static function accepted(array $branches): string
    {
        $named = [];

        foreach ($branches as $branch) {
            if ($branch === self::OPEN) {
                return 'a value this schema can carry';
            }

            $words = $branch['values'] === null
                ? ($branch['types'] ?? [])
                : array_map(static fn (mixed $value): string => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $branch['values']);

            foreach ($words as $word) {
                if (! in_array($word, $named, true)) {
                    $named[] = $word;
                }
            }
        }

        if ($named === []) {
            return 'a value this schema can carry';
        }

        $kept = array_slice($named, 0, self::NAMED);
        $rest = count($named) - count($kept);

        return implode('/', $kept).($rest > 0 ? sprintf(' (and %d more)', $rest) : '');
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
