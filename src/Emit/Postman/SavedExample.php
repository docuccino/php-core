<?php

declare(strict_types=1);

namespace Docuccino\Core\Emit\Postman;

use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Docuccino\Core\Emit\SchemaExampleFactory;
use Docuccino\Core\Support\Arr;
use stdClass;

/**
 * One documented response as a Postman saved example.
 *
 * `responseTime` and `timings` are absent rather than null — this is a documented shape, not a
 * recorded call, and a `null` there would claim we measured something and got nothing.
 *
 * @internal
 */
final class SavedExample
{
    /** Reason phrases for the statuses an API documents. Unknown codes get an empty phrase. */
    private const array REASONS = [
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        204 => 'No Content',
        206 => 'Partial Content',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        409 => 'Conflict',
        410 => 'Gone',
        412 => 'Precondition Failed',
        413 => 'Content Too Large',
        415 => 'Unsupported Media Type',
        418 => "I'm a teapot",
        422 => 'Unprocessable Content',
        423 => 'Locked',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        451 => 'Unavailable For Legal Reasons',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
    ];

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $components
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public static function of(int $code, array $response, array $components, array $request, SchemaExampleFactory $examples): array
    {
        // A $ref'd response resolves first, so an error shape shared across operations yields the same
        // example wherever it is referenced.
        $response = self::dereference($response, $components);

        $content = Arr::stringKeyed(is_array($response['content'] ?? null) ? $response['content'] : []);
        $types = array_map(strval(...), array_keys($content));
        $mediaType = $types === [] ? null : Body::preferred($types, ['application/json']);

        $example = [
            'name' => self::name($code, $response),
            'originalRequest' => $request,
            'status' => self::REASONS[$code] ?? '',
            'code' => $code,
            'header' => self::headers($mediaType, $response, $components, $examples),
            'cookie' => [],
        ];

        [$body, $language] = self::body($mediaType, $content, $components, $examples);
        $example['body'] = $body;
        $example['_postman_previewlanguage'] = $language;

        return $example;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private static function name(int $code, array $response): string
    {
        $reason = self::REASONS[$code] ?? '';
        $label = $reason === '' ? (string) $code : $code.' '.$reason;

        $description = Description::text($response['description'] ?? null);

        return $description === '' ? $label : $label.' — '.$description;
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $components
     * @return list<array<string, mixed>>
     */
    private static function headers(?string $mediaType, array $response, array $components, SchemaExampleFactory $examples): array
    {
        $headers = [];

        if ($mediaType !== null) {
            $headers[] = ['key' => 'Content-Type', 'value' => $mediaType];
        }

        $declared = Arr::stringKeyed(is_array($response['headers'] ?? null) ? $response['headers'] : []);
        $names = array_map(strval(...), array_keys($declared));
        sort($names, SORT_STRING);

        foreach ($names as $name) {
            $header = Arr::stringKeyed(is_array($declared[$name] ?? null) ? $declared[$name] : []);
            $schema = is_array($header['schema'] ?? null) ? Arr::stringKeyed($header['schema']) : [];
            $value = $schema === [] ? '' : $examples->value($schema, $components);

            $entry = ['key' => $name, 'value' => is_scalar($value) ? (string) $value : ''];

            $description = Description::text($header['description'] ?? null);
            if ($description !== '') {
                $entry['description'] = $description;
            }

            $headers[] = $entry;
        }

        return $headers;
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $components
     * @return array{string, string}
     */
    private static function body(?string $mediaType, array $content, array $components, SchemaExampleFactory $examples): array
    {
        if ($mediaType === null) {
            return ['', 'text'];
        }

        $media = Arr::stringKeyed(is_array($content[$mediaType] ?? null) ? $content[$mediaType] : []);
        $schema = is_array($media['schema'] ?? null) ? Arr::stringKeyed($media['schema']) : [];
        $json = $mediaType === 'application/json' || str_ends_with($mediaType, '+json');

        if (! $json) {
            return ['', 'text'];
        }

        // What the response says it looks like — its `example`, or the lowest key of its `examples` map
        // — before anything derived from the schema. Those members sit beside the schema, not in it.
        $stated = $examples->illustration($media);

        if ($stated === null && $schema === []) {
            return ['', 'json'];
        }

        $value = $stated === null ? $examples->value($schema, $components) : $stated[0];

        return [rtrim((new CanonicalJsonSerializer)->serialize($value ?? new stdClass), "\n"), 'json'];
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $components
     * @return array<string, mixed>
     */
    private static function dereference(array $response, array $components): array
    {
        $ref = $response['$ref'] ?? null;

        if (! is_string($ref) || ! str_starts_with($ref, '#/components/responses/')) {
            return $response;
        }

        $name = substr($ref, strlen('#/components/responses/'));
        $declared = Arr::stringKeyed(is_array($components['responses'] ?? null) ? $components['responses'] : []);
        $target = $declared[$name] ?? null;

        return is_array($target) ? Arr::stringKeyed($target) : $response;
    }
}
