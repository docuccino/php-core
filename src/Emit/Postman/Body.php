<?php

declare(strict_types=1);

namespace Docuccino\Core\Emit\Postman;

use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Docuccino\Core\Contract\Pointer;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Emit\SchemaExampleFactory;
use Docuccino\Core\Support\Arr;
use stdClass;

/**
 * Turns a request body's Media Type Object into Postman's `body` member.
 *
 * The whole media type, not just its schema: `example` and `examples` sit beside the schema rather than
 * in it, and they are what an author said the payload looks like — a collection that ignored them would
 * ship `{"id": 0, "name": "string"}` where the document publishes a real one.
 *
 * The JSON body is itself written through {@see CanonicalJsonSerializer}, so the string nested inside
 * the collection is as deterministic as the collection around it.
 *
 * **A schema is READ, not assumed to be spelled out.** `#/components/schemas/CreateUser` and the object
 * written inline describe one payload, and a collection where only one of the two carries a body is a
 * collection whose contents depend on whether a shape happened to be shared. {@see Ref} follows the
 * chain — the one resolver, hop-capped and cycle-terminating — and {@see fields()} folds `allOf` for
 * the form kinds, which need the property list itself rather than a value built from it.
 *
 * @internal
 */
final class Body
{
    /** An `allOf` nested deeper than this is not a shape anybody wrote as a form. */
    private const int MAX_COMPOSITION_DEPTH = 8;

    /**
     * Which media type to build from. A fixed preference, then lowest-sorted — never map order, which
     * no author chose.
     *
     * @param  list<string>  $available
     * @param  list<string>  $preference
     */
    public static function preferred(array $available, array $preference): string
    {
        foreach ($preference as $candidate) {
            if (in_array($candidate, $available, true)) {
                return $candidate;
            }
        }

        $json = array_values(array_filter($available, static fn (string $t): bool => str_ends_with($t, '+json')));
        sort($json, SORT_STRING);
        if ($json !== []) {
            return $json[0];
        }

        sort($available, SORT_STRING);

        return $available[0] ?? 'application/json';
    }

    /**
     * @param  array<string, mixed>  $media  the Media Type Object: `schema`, and whatever illustrates it
     * @param  array<string, mixed>  $components
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>|null
     */
    public static function of(string $mediaType, array $media, array $components, SchemaExampleFactory $examples, string $signature, array &$diagnostics): ?array
    {
        $base = strtolower(trim(explode(';', $mediaType)[0]));

        [$schema, $unresolved] = self::schema($media, $components);

        if ($unresolved !== null) {
            self::reportUnresolved($base, $unresolved, $signature, $diagnostics);
        }

        if ($base === 'application/json' || str_ends_with($base, '+json')) {
            return self::raw(self::json($media, $schema, $components, $examples), 'json');
        }

        if ($base === 'application/x-www-form-urlencoded' || $base === 'multipart/form-data') {
            return self::form($base, $media, $schema, $components, $examples, $signature, $diagnostics);
        }

        if (str_starts_with($base, 'text/')) {
            return self::raw(self::text($media, $schema, $components, $examples), 'text');
        }

        // XML from a JSON Schema would be a guess, and a guessed body is worse than an empty one the
        // consumer fills in knowingly.
        self::reportMediaType($base, $diagnostics);

        return $base === 'application/octet-stream'
            ? ['mode' => 'formdata', 'formdata' => [['key' => 'file', 'type' => 'file', 'src' => null]]]
            : self::raw('', 'text');
    }

    /**
     * @param  array<string, mixed>  $media
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $components
     */
    private static function json(array $media, array $schema, array $components, SchemaExampleFactory $examples): string
    {
        $stated = $examples->illustration($media);

        if ($stated === null && $schema === []) {
            return '';
        }

        $value = $stated === null ? $examples->member($schema, $components) : [$stated[0]];

        // A schema nothing satisfies has no body to show, and an empty one claims nothing rather than
        // claiming a payload the server will reject.
        if ($value === null) {
            return '';
        }

        // An object with no properties must serialise as `{}`; an empty PHP array would render `[]`,
        // which is a body that lies about its own shape.
        return rtrim((new CanonicalJsonSerializer)->serialize($value[0] ?? new stdClass), "\n");
    }

    /**
     * @param  array<string, mixed>  $media
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $components
     */
    private static function text(array $media, array $schema, array $components, SchemaExampleFactory $examples): string
    {
        $stated = $examples->illustration($media);
        $value = $stated !== null
            ? $stated[0]
            : ($schema === [] ? '' : $examples->value($schema, $components));

        return is_string($value) ? $value : '';
    }

    /**
     * @param  array<string, mixed>  $media
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $components
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>|null
     */
    private static function form(string $base, array $media, array $schema, array $components, SchemaExampleFactory $examples, string $signature, array &$diagnostics): ?array
    {
        $folded = [];
        [$properties, $required] = self::fields($schema, $components, $folded);

        if ($properties === []) {
            if ($schema !== [] && ($schema['type'] ?? 'object') !== 'object') {
                $diagnostics[] = new Diagnostic(
                    severity: Severity::Warning,
                    code: 'postman.body-not-object',
                    message: sprintf('The %s body is not an object, and a form body is a list of fields — the collection sends it empty.', $base),
                    routeSignature: $signature,
                );
            }

            // No fields: omit `body` entirely rather than shipping an empty array.
            return null;
        }

        $keys = array_keys($properties);
        sort($keys, SORT_STRING);

        // A form body an author illustrated supplies each field it names; the rest still come off the shape.
        $stated = $examples->illustration($media);
        $illustrated = is_array($stated[0] ?? null) ? Arr::stringKeyed($stated[0]) : [];

        $mode = $base === 'multipart/form-data' ? 'formdata' : 'urlencoded';
        $fields = [];

        foreach ($keys as $key) {
            $key = (string) $key;
            $member = $examples->member($properties[$key] ?? null, $components);

            // The illustration decides a field's VALUE; the schema decides which fields exist at all.
            // So a member nothing satisfies is left out even where the body's example names it — that
            // is the example contradicting its own schema, which the example lint reports.
            if ($member === null) {
                continue;
            }

            $fields[] = self::field(
                $mode,
                $key,
                Arr::stringKeyed(is_array($properties[$key] ?? null) ? $properties[$key] : []),
                array_key_exists($key, $illustrated) ? [$illustrated[$key]] : $member,
                in_array($key, $required, true),
            );
        }

        // No field the consumer may send: omit `body` entirely rather than shipping an empty array.
        return $fields === [] ? null : ['mode' => $mode, $mode => $fields];
    }

    /**
     * The media type's schema with its `$ref` chain followed, and the reference that landed nowhere.
     *
     * Followed HERE rather than in each body kind, so `json`, `text` and the two form kinds all read
     * one shape: a payload does not change because its schema was shared. A reference that resolves to
     * nothing degrades to the empty schema — the `$ref` node says nothing about the payload, and
     * reading `type` or `properties` off it would answer for a shape nobody wrote.
     *
     * @param  array<string, mixed>  $media
     * @param  array<string, mixed>  $components
     * @return array{0: array<string, mixed>, 1: string|null}
     */
    private static function schema(array $media, array $components): array
    {
        $written = is_array($media['schema'] ?? null) ? Arr::stringKeyed($media['schema']) : [];

        [$schema, , $unresolved] = Ref::follow($written, $components);

        return [$unresolved === null ? $schema : [], $unresolved];
    }

    /**
     * The fields a form body has, as `[properties, required]`, with `allOf` folded flat.
     *
     * A form body IS a list of fields, so a shape composed of several object schemas has the same
     * fields as the one that wrote them out — and a collection that read only the outer object would
     * send a request missing every inherited one. A conjunction cannot disagree with itself, so a
     * property named twice is one property and the outer schema, then the earlier branch, keeps the
     * say; `required` accumulates, since `in_array` is all it is asked.
     *
     * **Bounded by the document, not by the depth cap.** A pointer is folded ONCE across the whole
     * walk, so `k` branches referencing a shape already folded cost `k` visits rather than `k` to the
     * power of the cap — which is seconds of pure CPU on a document under a kilobyte, at flat memory,
     * so no limit anywhere stops it. Skipping costs the answer nothing: a conjunction is a union, and
     * whatever is behind a pointer met twice is already in the set the first visit merged.
     *
     * @param  array<string, mixed>  $schema  the media type's schema, `$ref` already followed
     * @param  array<string, mixed>  $components
     * @param  array<string, true>  $folded  the pointers this walk has already folded
     * @return array{0: array<string, mixed>, 1: list<mixed>}
     */
    private static function fields(array $schema, array $components, array &$folded, int $depth = 0): array
    {
        $properties = is_array($schema['properties'] ?? null) ? Arr::stringKeyed($schema['properties']) : [];
        $required = is_array($schema['required'] ?? null) ? array_values($schema['required']) : [];

        $branches = is_array($schema['allOf'] ?? null) ? array_values($schema['allOf']) : [];

        if ($depth >= self::MAX_COMPOSITION_DEPTH) {
            return [$properties, $required];
        }

        foreach ($branches as $branch) {
            if (! is_array($branch)) {
                continue;
            }

            [$resolved, $where, $unresolved] = Ref::follow(Arr::stringKeyed($branch), $components);

            if ($unresolved !== null) {
                continue;
            }

            // An inline branch is written out once and so is walked once; a pointer can be written any
            // number of times, and is folded on the first of them.
            if ($where !== []) {
                $pointer = Pointer::of($where);

                if (isset($folded[$pointer])) {
                    continue;
                }

                $folded[$pointer] = true;
            }

            [$theirs, $theirRequired] = self::fields($resolved, $components, $folded, $depth + 1);

            $properties += $theirs;
            $required = [...$required, ...$theirRequired];
        }

        return [$properties, $required];
    }

    /**
     * Deduplicated by reference across the whole document, for the same reason
     * {@see reportMediaType()} is by media type: one broken pointer shared by 40 operations is one
     * defect, and 40 warnings would bury the other 39 diagnostics. It carries the route signature all
     * the same — the first route to meet it is a place to go and look, which a media type has no
     * equivalent of.
     *
     * @param  list<Diagnostic>  $diagnostics
     */
    private static function reportUnresolved(string $base, string $reference, string $signature, array &$diagnostics): void
    {
        foreach ($diagnostics as $diagnostic) {
            if ($diagnostic->code === 'postman.body-unresolved' && str_contains($diagnostic->message, sprintf('`%s`', $reference))) {
                return;
            }
        }

        $diagnostics[] = new Diagnostic(
            severity: Severity::Warning,
            code: 'postman.body-unresolved',
            message: sprintf(
                'The %s body is documented as `%s`, which the document does not define, so the request is sent with no body.',
                $base,
                $reference,
            ),
            routeSignature: $signature,
        );
    }

    /**
     * @param  array<string, mixed>  $property
     * @param  array{mixed}  $value  what to send: the body's own example for this field, or the shape's
     * @return array<string, mixed>
     */
    private static function field(string $mode, string $key, array $property, array $value, bool $required): array
    {
        $binary = ($property['format'] ?? null) === 'binary' || isset($property['contentMediaType']);

        // A file field carries no `src`: pointing at a path on the build machine would put this
        // machine's filesystem into a committed artifact, and it would be wrong on every other one.
        $field = $binary && $mode === 'formdata'
            ? ['key' => $key, 'type' => 'file', 'src' => null]
            : ['key' => $key, 'value' => self::scalar($value[0]), 'type' => 'text'];

        if (! $required) {
            $field['disabled'] = true;
        }

        $description = Description::text($property['description'] ?? null);
        if ($description !== '') {
            $field['description'] = $description;
        }

        return $field;
    }

    /**
     * @return array<string, mixed>
     */
    private static function raw(string $body, string $language): array
    {
        return ['mode' => 'raw', 'raw' => $body, 'options' => ['raw' => ['language' => $language]]];
    }

    /**
     * Deduplicated by media type across the WHOLE document: a document with 300 XML endpoints owes its
     * reader one warning naming `application/xml`, not 300 that bury every other diagnostic. That is
     * also why it carries no route signature — the one warning would name whichever route was met
     * first, which is a fact about encounter order rather than about the media type.
     *
     * @param  list<Diagnostic>  $diagnostics
     */
    private static function reportMediaType(string $base, array &$diagnostics): void
    {
        foreach ($diagnostics as $diagnostic) {
            if ($diagnostic->code === 'postman.body-media-type' && str_contains($diagnostic->message, sprintf('`%s`', $base))) {
                return;
            }
        }

        $diagnostics[] = new Diagnostic(
            severity: Severity::Warning,
            code: 'postman.body-media-type',
            message: sprintf('No example body can be built for `%s`, so requests using it are sent empty.', $base),
        );
    }

    private static function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            return $value;
        }

        return is_array($value) ? implode(',', array_map(self::scalar(...), $value)) : '';
    }
}
