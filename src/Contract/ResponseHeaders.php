<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract;

/**
 * The `headers` map of one documented response, read as the {@see ContractParameter}s OAS says header
 * objects are — a parameter without `name` and `in` — so they go through the same coercion and the same
 * {@see SchemaCheck} a request header does.
 *
 * A `Content-Type` entry is dropped: OpenAPI says a response header of that name SHALL be ignored,
 * because `content` is what describes the media type.
 *
 * The map comes back sorted by name. Every index in this package sorts, for the same reason: an order
 * read off the document's key order would make the order of the violations a response produces a
 * function of how the document happened to be written rather than of what it says.
 *
 * @internal
 */
final class ResponseHeaders
{
    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $response  the response object, `$ref` already followed
     * @param  list<string>  $segments  pointer segments addressing that response object
     * @return list<ContractParameter>
     */
    public static function of(array $document, array $response, array $segments): array
    {
        $headers = $response['headers'] ?? null;

        if (! is_array($headers)) {
            return [];
        }

        $names = array_map(strval(...), array_keys($headers));
        sort($names);

        $out = [];
        foreach ($names as $name) {
            if (strcasecmp($name, 'Content-Type') === 0) {
                continue;
            }

            $header = $headers[$name];

            /** @var array<string, mixed> $node */
            $node = is_array($header) ? $header : [];
            [$definition, $where, $dangling] = Refs::follow($document, $node, [...$segments, 'headers', $name]);

            $out[] = new ContractParameter(
                name: $name,
                in: 'header',
                required: ($definition['required'] ?? false) === true,
                definition: $definition,
                segments: $where,
                label: 'the response header '.$name,
                danglingRef: $dangling,
            );
        }

        return $out;
    }
}
