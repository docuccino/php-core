<?php

declare(strict_types=1);

namespace Docuccino\Core\Emit\Postman;

use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Emit\SchemaExampleFactory;
use Docuccino\Core\Support\Arr;
use stdClass;

/**
 * Turns a request body's media type and schema into Postman's `body` member.
 *
 * The JSON body is itself written through {@see CanonicalJsonSerializer}, so the string nested inside
 * the collection is as deterministic as the collection around it.
 *
 * @internal
 */
final class Body
{
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
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $components
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>|null
     */
    public static function of(string $mediaType, array $schema, array $components, SchemaExampleFactory $examples, string $signature, array &$diagnostics): ?array
    {
        $base = strtolower(trim(explode(';', $mediaType)[0]));

        if ($base === 'application/json' || str_ends_with($base, '+json')) {
            return self::raw(self::json($schema, $components, $examples), 'json');
        }

        if ($base === 'application/x-www-form-urlencoded' || $base === 'multipart/form-data') {
            return self::form($base, $schema, $components, $examples, $signature, $diagnostics);
        }

        if (str_starts_with($base, 'text/')) {
            return self::raw(self::text($schema, $components, $examples), 'text');
        }

        // XML from a JSON Schema would be a guess, and a guessed body is worse than an empty one the
        // consumer fills in knowingly.
        self::reportMediaType($base, $signature, $diagnostics);

        return $base === 'application/octet-stream'
            ? ['mode' => 'formdata', 'formdata' => [['key' => 'file', 'type' => 'file', 'src' => null]]]
            : self::raw('', 'text');
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $components
     */
    private static function json(array $schema, array $components, SchemaExampleFactory $examples): string
    {
        if ($schema === []) {
            return '';
        }

        $value = $examples->value($schema, $components);

        // An object with no properties must serialise as `{}`; an empty PHP array would render `[]`,
        // which is a body that lies about its own shape.
        return rtrim((new CanonicalJsonSerializer)->serialize($value ?? new stdClass), "\n");
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $components
     */
    private static function text(array $schema, array $components, SchemaExampleFactory $examples): string
    {
        $value = $schema === [] ? '' : $examples->value($schema, $components);

        return is_string($value) ? $value : '';
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $components
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>|null
     */
    private static function form(string $base, array $schema, array $components, SchemaExampleFactory $examples, string $signature, array &$diagnostics): ?array
    {
        $properties = is_array($schema['properties'] ?? null) ? Arr::stringKeyed($schema['properties']) : [];

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

        $required = is_array($schema['required'] ?? null) ? array_values($schema['required']) : [];

        $keys = array_keys($properties);
        sort($keys, SORT_STRING);

        $mode = $base === 'multipart/form-data' ? 'formdata' : 'urlencoded';
        $fields = [];

        foreach ($keys as $key) {
            $key = (string) $key;
            $property = Arr::stringKeyed(is_array($properties[$key] ?? null) ? $properties[$key] : []);
            $fields[] = self::field($mode, $key, $property, $components, $examples, in_array($key, $required, true));
        }

        return ['mode' => $mode, $mode => $fields];
    }

    /**
     * @param  array<string, mixed>  $property
     * @param  array<string, mixed>  $components
     * @return array<string, mixed>
     */
    private static function field(string $mode, string $key, array $property, array $components, SchemaExampleFactory $examples, bool $required): array
    {
        $binary = ($property['format'] ?? null) === 'binary' || isset($property['contentMediaType']);

        // A file field carries no `src`: pointing at a path on the build machine would put this
        // machine's filesystem into a committed artifact, and it would be wrong on every other one.
        $field = $binary && $mode === 'formdata'
            ? ['key' => $key, 'type' => 'file', 'src' => null]
            : ['key' => $key, 'value' => self::scalar($examples->value($property, $components)), 'type' => 'text'];

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
     * reader one warning naming `application/xml`, not 300 that bury every other diagnostic.
     *
     * @param  list<Diagnostic>  $diagnostics
     */
    private static function reportMediaType(string $base, string $signature, array &$diagnostics): void
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
