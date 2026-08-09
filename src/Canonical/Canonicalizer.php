<?php

declare(strict_types=1);

namespace Docuccino\Core\Canonical;

use Docuccino\Core\Document\PathItem;
use stdClass;

/**
 * Normative canonicalisation (design §3): a fixed member order per object type, map keys
 * sorted by Unicode code point, a fixed HTTP method order, parameters sorted by (in-rank,
 * name), and declaration-order-preserving dedup for tags/security/enum.
 *
 * Handlers accept `mixed` and pass malformed values through unchanged so canonicalisation
 * is total. Empty object-typed members are emitted as {@see stdClass} so the serializer
 * writes `{}` rather than `[]`; `x-*` members other than `x-docuccino` pass through verbatim.
 *
 * @internal
 */
final class Canonicalizer
{
    private const array PARAMETER_IN_RANK = ['path' => 0, 'query' => 1, 'header' => 2, 'cookie' => 3];

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public function canonicalize(array $document): array
    {
        return $this->build($document, [
            '$schema' => $this->keep(...),
            'uir' => $this->keep(...),
            'openapi' => $this->keep(...),
            'jsonSchemaDialect' => $this->keep(...),
            'info' => $this->canonicalizeInfo(...),
            'servers' => fn (mixed $v): mixed => $this->mapList($v, $this->canonicalizeServer(...)),
            'security' => $this->canonicalizeSecurityRequirements(...),
            'tags' => fn (mixed $v): mixed => $this->mapList($v, $this->canonicalizeTag(...)),
            'paths' => fn (mixed $v): mixed => $this->sortedMap($v, $this->canonicalizePathItem(...)),
            'webhooks' => fn (mixed $v): mixed => $this->sortedMap($v, $this->canonicalizePathItem(...)),
            'components' => $this->canonicalizeComponents(...),
            'x-docuccino' => $this->canonicalizeDocuccino(...),
        ]);
    }

    /**
     * Structural view of a schema used for inline-schema identity: descriptions, examples
     * and `x-docuccino` stripped recursively, then canonicalised.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>|stdClass
     */
    public function canonicalizeSchemaForIdentity(array $schema): array|stdClass
    {
        return $this->canonicalizeSchema($this->stripForStructuralHash($schema));
    }

    private function keep(mixed $value): mixed
    {
        return $value;
    }

    private function compareKeys(int|string $a, int|string $b): int
    {
        return strcmp((string) $a, (string) $b);
    }

    /**
     * @param  array<mixed, mixed>  $node
     * @param  array<string, callable(mixed): mixed>  $handlers
     * @return array<string, mixed>
     */
    private function build(array $node, array $handlers): array
    {
        $out = [];

        foreach ($handlers as $key => $handler) {
            if (array_key_exists($key, $node)) {
                $out[$key] = $handler($node[$key]);
            }
        }

        $unknown = array_diff_key($node, $handlers);
        uksort($unknown, $this->compareKeys(...));

        foreach ($unknown as $key => $value) {
            $key = (string) $key;
            $out[$key] = str_starts_with($key, 'x-') ? $value : $this->canonicalizeGeneric($value);
        }

        return $out;
    }

    /**
     * @param  callable(array<mixed, mixed>): array<string, mixed>  $builder
     * @return array<string, mixed>|stdClass
     */
    private function object(mixed $node, callable $builder): array|stdClass
    {
        if (! is_array($node)) {
            return $node instanceof stdClass ? $node : new stdClass;
        }

        return $this->toObject($builder($node));
    }

    /**
     * @param  array<string, mixed>  $built
     * @return array<string, mixed>|stdClass
     */
    private function toObject(array $built): array|stdClass
    {
        return $built === [] ? new stdClass : $built;
    }

    /**
     * @return array<string, mixed>|stdClass
     */
    private function canonicalizeInfo(mixed $node): array|stdClass
    {
        return $this->object($node, fn (array $info) => $this->build($info, [
            'title' => $this->keep(...),
            'summary' => $this->keep(...),
            'description' => $this->keep(...),
            'termsOfService' => $this->keep(...),
            'contact' => fn (mixed $v): mixed => $this->object($v, fn (array $c) => $this->build($c, [
                'name' => $this->keep(...),
                'url' => $this->keep(...),
                'email' => $this->keep(...),
            ])),
            'license' => fn (mixed $v): mixed => $this->object($v, fn (array $l) => $this->build($l, [
                'name' => $this->keep(...),
                'identifier' => $this->keep(...),
                'url' => $this->keep(...),
            ])),
            'version' => $this->keep(...),
            'x-docuccino' => $this->canonicalizeDocuccino(...),
        ]));
    }

    /**
     * @return array<string, mixed>|stdClass
     */
    private function canonicalizeServer(mixed $node): array|stdClass
    {
        return $this->object($node, fn (array $server) => $this->build($server, [
            'url' => $this->keep(...),
            'description' => $this->keep(...),
            'variables' => fn (mixed $v): mixed => $this->sortedMap($v, fn (mixed $var): mixed => $this->object($var, fn (array $variable) => $this->build($variable, [
                'enum' => $this->canonicalizeStringList(...),
                'default' => $this->keep(...),
                'description' => $this->keep(...),
            ]))),
            'x-docuccino' => $this->canonicalizeDocuccino(...),
        ]));
    }

    /**
     * @return array<string, mixed>|stdClass
     */
    private function canonicalizeTag(mixed $node): array|stdClass
    {
        return $this->object($node, fn (array $tag) => $this->build($tag, [
            'name' => $this->keep(...),
            'description' => $this->keep(...),
            'externalDocs' => $this->canonicalizeExternalDocs(...),
            'x-docuccino' => $this->canonicalizeDocuccino(...),
        ]));
    }

    /**
     * @return array<string, mixed>|stdClass
     */
    private function canonicalizeExternalDocs(mixed $node): array|stdClass
    {
        return $this->object($node, fn (array $docs) => $this->build($docs, [
            'description' => $this->keep(...),
            'url' => $this->keep(...),
        ]));
    }

    /**
     * @return array<string, mixed>|stdClass
     */
    private function canonicalizePathItem(mixed $node): array|stdClass
    {
        return $this->object($node, function (array $item) {
            $handlers = [
                'x-docuccino' => $this->canonicalizeDocuccino(...),
                '$ref' => $this->keep(...),
                'summary' => $this->keep(...),
                'description' => $this->keep(...),
                'servers' => fn (mixed $v): mixed => $this->mapList($v, $this->canonicalizeServer(...)),
                'parameters' => $this->canonicalizeParameterList(...),
            ];

            foreach (PathItem::METHODS as $method) {
                $handlers[$method] = $this->canonicalizeOperation(...);
            }

            return $this->build($item, $handlers);
        });
    }

    /**
     * @return array<string, mixed>|stdClass
     */
    private function canonicalizeOperation(mixed $node): array|stdClass
    {
        return $this->object($node, fn (array $operation) => $this->build($operation, [
            'x-docuccino' => $this->canonicalizeDocuccino(...),
            'operationId' => $this->keep(...),
            'summary' => $this->keep(...),
            'description' => $this->keep(...),
            'externalDocs' => $this->canonicalizeExternalDocs(...),
            'deprecated' => $this->keep(...),
            'tags' => $this->canonicalizeStringList(...),
            'security' => $this->canonicalizeSecurityRequirements(...),
            'servers' => fn (mixed $v): mixed => $this->mapList($v, $this->canonicalizeServer(...)),
            'parameters' => $this->canonicalizeParameterList(...),
            'requestBody' => $this->canonicalizeRequestBody(...),
            'responses' => fn (mixed $v): mixed => $this->sortedMap($v, $this->canonicalizeResponse(...)),
            'callbacks' => fn (mixed $v): mixed => $this->sortedMap($v, fn (mixed $cb): mixed => $this->sortedMap($cb, $this->canonicalizePathItem(...))),
        ]));
    }

    /**
     * @return list<array<string, mixed>|stdClass>
     */
    private function canonicalizeParameterList(mixed $node): array
    {
        if (! is_array($node)) {
            return [];
        }

        $parameters = [];
        foreach ($node as $parameter) {
            $parameters[] = $this->canonicalizeParameter($parameter);
        }

        usort($parameters, function (mixed $a, mixed $b): int {
            $rankA = $this->parameterRank($a);
            $rankB = $this->parameterRank($b);

            return $rankA <=> $rankB ?: strcmp($this->parameterName($a), $this->parameterName($b));
        });

        return $parameters;
    }

    private function parameterRank(mixed $parameter): int
    {
        if (is_array($parameter) && isset($parameter['in']) && is_string($parameter['in'])) {
            return self::PARAMETER_IN_RANK[$parameter['in']] ?? 4;
        }

        return 5;
    }

    private function parameterName(mixed $parameter): string
    {
        if (is_array($parameter) && isset($parameter['name']) && is_string($parameter['name'])) {
            return $parameter['name'];
        }

        return '';
    }

    /**
     * @return array<string, mixed>|stdClass
     */
    private function canonicalizeParameter(mixed $node): array|stdClass
    {
        return $this->object($node, fn (array $parameter) => $this->build($parameter, [
            'x-docuccino' => $this->canonicalizeDocuccino(...),
            '$ref' => $this->keep(...),
            'name' => $this->keep(...),
            'in' => $this->keep(...),
            'description' => $this->keep(...),
            'required' => $this->keep(...),
            'deprecated' => $this->keep(...),
            'allowEmptyValue' => $this->keep(...),
            'style' => $this->keep(...),
            'explode' => $this->keep(...),
            'allowReserved' => $this->keep(...),
            'schema' => $this->canonicalizeSchema(...),
            'example' => $this->keep(...),
            'examples' => fn (mixed $v): mixed => $this->sortedMap($v, $this->canonicalizeExample(...)),
            'content' => fn (mixed $v): mixed => $this->sortedMap($v, $this->canonicalizeMediaType(...)),
        ]));
    }

    /**
     * @return array<string, mixed>|stdClass
     */
    private function canonicalizeRequestBody(mixed $node): array|stdClass
    {
        return $this->object($node, fn (array $body) => $this->build($body, [
            'x-docuccino' => $this->canonicalizeDocuccino(...),
            '$ref' => $this->keep(...),
            'description' => $this->keep(...),
            'required' => $this->keep(...),
            'content' => fn (mixed $v): mixed => $this->sortedMap($v, $this->canonicalizeMediaType(...)),
        ]));
    }

    /**
     * @return array<string, mixed>|stdClass
     */
    private function canonicalizeResponse(mixed $node): array|stdClass
    {
        return $this->object($node, fn (array $response) => $this->build($response, [
            'x-docuccino' => $this->canonicalizeDocuccino(...),
            '$ref' => $this->keep(...),
            'summary' => $this->keep(...),
            'description' => $this->keep(...),
            'headers' => fn (mixed $v): mixed => $this->sortedMap($v, $this->canonicalizeHeader(...)),
            'content' => fn (mixed $v): mixed => $this->sortedMap($v, $this->canonicalizeMediaType(...)),
            'links' => fn (mixed $v): mixed => $this->sortedMap($v, $this->canonicalizeGeneric(...)),
        ]));
    }

    /**
     * @return array<string, mixed>|stdClass
     */
    private function canonicalizeHeader(mixed $node): array|stdClass
    {
        return $this->object($node, fn (array $header) => $this->build($header, [
            'x-docuccino' => $this->canonicalizeDocuccino(...),
            '$ref' => $this->keep(...),
            'description' => $this->keep(...),
            'required' => $this->keep(...),
            'deprecated' => $this->keep(...),
            'style' => $this->keep(...),
            'explode' => $this->keep(...),
            'schema' => $this->canonicalizeSchema(...),
            'example' => $this->keep(...),
            'examples' => fn (mixed $v): mixed => $this->sortedMap($v, $this->canonicalizeExample(...)),
            'content' => fn (mixed $v): mixed => $this->sortedMap($v, $this->canonicalizeMediaType(...)),
        ]));
    }

    /**
     * @return array<string, mixed>|stdClass
     */
    private function canonicalizeMediaType(mixed $node): array|stdClass
    {
        return $this->object($node, fn (array $media) => $this->build($media, [
            'x-docuccino' => $this->canonicalizeDocuccino(...),
            'schema' => $this->canonicalizeSchema(...),
            'example' => $this->keep(...),
            'examples' => fn (mixed $v): mixed => $this->sortedMap($v, $this->canonicalizeExample(...)),
            'encoding' => fn (mixed $v): mixed => $this->sortedMap($v, $this->canonicalizeGeneric(...)),
        ]));
    }

    /**
     * @return array<string, mixed>|stdClass
     */
    private function canonicalizeExample(mixed $node): array|stdClass
    {
        return $this->object($node, fn (array $example) => $this->build($example, [
            'x-docuccino' => $this->canonicalizeDocuccino(...),
            'summary' => $this->keep(...),
            'description' => $this->keep(...),
            'value' => $this->canonicalizeGeneric(...),
            'externalValue' => $this->keep(...),
        ]));
    }

    /**
     * @return array<string, mixed>|stdClass
     */
    private function canonicalizeComponents(mixed $node): array|stdClass
    {
        return $this->object($node, fn (array $components) => $this->build($components, [
            'schemas' => fn (mixed $v): mixed => $this->sortedMap($v, $this->canonicalizeSchema(...)),
            'responses' => fn (mixed $v): mixed => $this->sortedMap($v, $this->canonicalizeResponse(...)),
            'parameters' => fn (mixed $v): mixed => $this->sortedMap($v, $this->canonicalizeParameter(...)),
            'examples' => fn (mixed $v): mixed => $this->sortedMap($v, $this->canonicalizeExample(...)),
            'requestBodies' => fn (mixed $v): mixed => $this->sortedMap($v, $this->canonicalizeRequestBody(...)),
            'headers' => fn (mixed $v): mixed => $this->sortedMap($v, $this->canonicalizeHeader(...)),
            'securitySchemes' => fn (mixed $v): mixed => $this->sortedMap($v, $this->canonicalizeGeneric(...)),
            'links' => fn (mixed $v): mixed => $this->sortedMap($v, $this->canonicalizeGeneric(...)),
            'callbacks' => fn (mixed $v): mixed => $this->sortedMap($v, fn (mixed $cb): mixed => $this->sortedMap($cb, $this->canonicalizePathItem(...))),
            'pathItems' => fn (mixed $v): mixed => $this->sortedMap($v, $this->canonicalizePathItem(...)),
            'x-docuccino' => $this->canonicalizeDocuccino(...),
        ]));
    }

    /**
     * @return array<string, mixed>|stdClass
     */
    private function canonicalizeSchema(mixed $node): array|stdClass
    {
        return $this->object($node, fn (array $schema) => $this->build($schema, [
            'x-docuccino' => $this->canonicalizeDocuccino(...),
            '$ref' => $this->keep(...),
            '$id' => $this->keep(...),
            '$anchor' => $this->keep(...),
            '$defs' => fn (mixed $v): mixed => $this->sortedMap($v, $this->canonicalizeSchema(...)),
            'title' => $this->keep(...),
            'description' => $this->keep(...),
            'type' => $this->keep(...),
            'format' => $this->keep(...),
            'enum' => $this->canonicalizeValueList(...),
            'const' => $this->canonicalizeGeneric(...),
            'default' => $this->canonicalizeGeneric(...),
            'multipleOf' => $this->keep(...),
            'maximum' => $this->keep(...),
            'exclusiveMaximum' => $this->keep(...),
            'minimum' => $this->keep(...),
            'exclusiveMinimum' => $this->keep(...),
            'maxLength' => $this->keep(...),
            'minLength' => $this->keep(...),
            'pattern' => $this->keep(...),
            'contentEncoding' => $this->keep(...),
            'contentMediaType' => $this->keep(...),
            'items' => $this->canonicalizeSchema(...),
            'prefixItems' => fn (mixed $v): mixed => $this->mapList($v, $this->canonicalizeSchema(...)),
            'contains' => $this->canonicalizeSchema(...),
            'maxItems' => $this->keep(...),
            'minItems' => $this->keep(...),
            'uniqueItems' => $this->keep(...),
            'properties' => fn (mixed $v): mixed => $this->sortedMap($v, $this->canonicalizeSchema(...)),
            'required' => $this->canonicalizeStringList(...),
            'additionalProperties' => fn (mixed $v): mixed => is_array($v) ? $this->canonicalizeSchema($v) : $v,
            'patternProperties' => fn (mixed $v): mixed => $this->sortedMap($v, $this->canonicalizeSchema(...)),
            'propertyNames' => $this->canonicalizeSchema(...),
            'maxProperties' => $this->keep(...),
            'minProperties' => $this->keep(...),
            'dependentRequired' => fn (mixed $v): mixed => $this->sortedMap($v, $this->canonicalizeStringList(...)),
            'dependentSchemas' => fn (mixed $v): mixed => $this->sortedMap($v, $this->canonicalizeSchema(...)),
            'allOf' => fn (mixed $v): mixed => $this->mapList($v, $this->canonicalizeSchema(...)),
            'anyOf' => fn (mixed $v): mixed => $this->mapList($v, $this->canonicalizeSchema(...)),
            'oneOf' => fn (mixed $v): mixed => $this->mapList($v, $this->canonicalizeSchema(...)),
            'not' => $this->canonicalizeSchema(...),
            'if' => $this->canonicalizeSchema(...),
            'then' => $this->canonicalizeSchema(...),
            'else' => $this->canonicalizeSchema(...),
            'discriminator' => $this->canonicalizeGeneric(...),
            'externalDocs' => $this->canonicalizeExternalDocs(...),
            'example' => $this->canonicalizeGeneric(...),
            'examples' => $this->canonicalizeGeneric(...),
            'readOnly' => $this->keep(...),
            'writeOnly' => $this->keep(...),
            'deprecated' => $this->keep(...),
            'nullable' => $this->keep(...),
        ]));
    }

    /**
     * @return array<string, mixed>|stdClass
     */
    private function canonicalizeDocuccino(mixed $node): array|stdClass
    {
        return $this->object($node, fn (array $xuir) => $this->build($xuir, [
            'id' => $this->keep(...),
            'provenance' => fn (mixed $v): mixed => $this->mapList($v, $this->canonicalizeProvenanceRecord(...)),
            'mock' => fn (mixed $v): mixed => $this->object($v, fn (array $mock) => $this->build($mock, [
                'faker' => $this->keep(...),
                'seedGroup' => $this->keep(...),
            ])),
            'document' => fn (mixed $v): mixed => $this->object($v, fn (array $doc) => $this->build($doc, [
                'id' => $this->keep(...),
                'configHash' => $this->keep(...),
                'contentHash' => $this->keep(...),
            ])),
            'generator' => fn (mixed $v): mixed => $this->object($v, fn (array $gen) => $this->build($gen, [
                'name' => $this->keep(...),
                'version' => $this->keep(...),
                'specVersion' => $this->keep(...),
            ])),
            'content' => fn (mixed $v): mixed => $this->object($v, fn (array $content) => $this->build($content, [
                'pages' => fn (mixed $p): mixed => $this->mapList($p, $this->canonicalizePage(...)),
                'nav' => fn (mixed $n): mixed => $this->mapList($n, $this->canonicalizeNavNode(...)),
            ])),
            'diagnostics' => fn (mixed $v): mixed => $this->mapList($v, $this->canonicalizeDiagnostic(...)),
        ]));
    }

    /**
     * @return array<string, mixed>|stdClass
     */
    private function canonicalizeProvenanceRecord(mixed $node): array|stdClass
    {
        return $this->object($node, fn (array $record) => $this->build($record, [
            'producer' => $this->keep(...),
            'layer' => $this->keep(...),
            'fields' => $this->canonicalizeStringList(...),
            'source' => $this->canonicalizeSource(...),
            'confidence' => $this->keep(...),
            'overrode' => fn (mixed $v): mixed => $this->mapList($v, fn (mixed $entry): mixed => $this->object($entry, fn (array $e) => $this->build($e, [
                'field' => $this->keep(...),
                'value' => $this->canonicalizeGeneric(...),
                'producer' => $this->keep(...),
            ]))),
        ]));
    }

    /**
     * @return array<string, mixed>|stdClass
     */
    private function canonicalizeSource(mixed $node): array|stdClass
    {
        return $this->object($node, fn (array $source) => $this->build($source, [
            'file' => $this->keep(...),
            'line' => $this->keep(...),
            'symbol' => $this->keep(...),
        ]));
    }

    /**
     * @return array<string, mixed>|stdClass
     */
    private function canonicalizeDiagnostic(mixed $node): array|stdClass
    {
        return $this->object($node, fn (array $diagnostic) => $this->build($diagnostic, [
            'severity' => $this->keep(...),
            'code' => $this->keep(...),
            'message' => $this->keep(...),
            'source' => $this->canonicalizeSource(...),
            'routeSignature' => $this->keep(...),
            'help' => $this->keep(...),
        ]));
    }

    /**
     * @return array<string, mixed>|stdClass
     */
    private function canonicalizePage(mixed $node): array|stdClass
    {
        return $this->object($node, fn (array $page) => $this->build($page, [
            'id' => $this->keep(...),
            'slug' => $this->keep(...),
            'title' => $this->keep(...),
            'summary' => $this->keep(...),
            'order' => $this->keep(...),
            'tags' => $this->canonicalizeStringList(...),
            'content' => $this->keep(...),
            'provenance' => fn (mixed $v): mixed => $this->mapList($v, $this->canonicalizeProvenanceRecord(...)),
        ]));
    }

    /**
     * A navigation-tree node (`x-docuccino.content.nav`): fixed member order, children recursed in
     * declaration order (nav order is meaningful and already deterministic from the compiler).
     *
     * @return array<string, mixed>|stdClass
     */
    private function canonicalizeNavNode(mixed $node): array|stdClass
    {
        return $this->object($node, fn (array $navNode) => $this->build($navNode, [
            'type' => $this->keep(...),
            'ref' => $this->keep(...),
            'title' => $this->keep(...),
            'children' => fn (mixed $v): mixed => $this->mapList($v, $this->canonicalizeNavNode(...)),
        ]));
    }

    /**
     * @return list<array<mixed, mixed>|stdClass>
     */
    private function canonicalizeSecurityRequirements(mixed $node): array
    {
        if (! is_array($node)) {
            return [];
        }

        $out = [];
        $seen = [];

        foreach ($node as $requirement) {
            if (! is_array($requirement)) {
                continue;
            }

            $canonical = $requirement;
            uksort($canonical, $this->compareKeys(...));

            $key = json_encode($canonical);
            $key = is_string($key) ? $key : '';

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[] = $canonical === [] ? new stdClass : $canonical;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function canonicalizeStringList(mixed $node): array
    {
        if (! is_array($node)) {
            return [];
        }

        $out = [];
        $seen = [];

        foreach ($node as $item) {
            if (! is_string($item) || isset($seen[$item])) {
                continue;
            }

            $seen[$item] = true;
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @return list<mixed>
     */
    private function canonicalizeValueList(mixed $node): array
    {
        if (! is_array($node)) {
            return [];
        }

        $out = [];
        $seen = [];

        foreach ($node as $item) {
            $key = json_encode($item);
            $key = is_string($key) ? $key : '';

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[] = $this->canonicalizeGeneric($item);
        }

        return $out;
    }

    /**
     * @param  callable(mixed): mixed  $child
     * @return list<mixed>
     */
    private function mapList(mixed $node, callable $child): array
    {
        if (! is_array($node)) {
            return [];
        }

        $out = [];
        foreach ($node as $item) {
            $out[] = $child($item);
        }

        return $out;
    }

    /**
     * @param  callable(mixed): mixed  $child
     * @return array<string, mixed>|stdClass
     */
    private function sortedMap(mixed $node, callable $child): array|stdClass
    {
        if (! is_array($node) || $node === []) {
            return new stdClass;
        }

        uksort($node, $this->compareKeys(...));

        $out = [];
        foreach ($node as $key => $value) {
            $out[(string) $key] = $child($value);
        }

        return $out;
    }

    private function canonicalizeGeneric(mixed $node): mixed
    {
        if (! is_array($node) || $node === []) {
            return $node;
        }

        if (array_is_list($node)) {
            return array_map($this->canonicalizeGeneric(...), $node);
        }

        uksort($node, $this->compareKeys(...));

        $out = [];
        foreach ($node as $key => $value) {
            $key = (string) $key;
            $out[$key] = str_starts_with($key, 'x-') ? $value : $this->canonicalizeGeneric($value);
        }

        return $out;
    }

    /**
     * Schema-annotation keywords stripped from inline-schema identity: prose that must not
     * change a schema's structural `sch:` id (design §2). Stripped ONLY in schema-annotation
     * position — never when they appear as property NAMES inside a `properties`-like map.
     */
    private const array SCHEMA_ANNOTATION_KEYS = ['description', 'title', 'example', 'examples', 'x-docuccino'];

    /**
     * Keywords whose value is a single subschema.
     */
    private const array SCHEMA_SUBSCHEMA_KEYS = ['items', 'contains', 'not', 'if', 'then', 'else', 'propertyNames', 'additionalProperties'];

    /**
     * Keywords whose value is a map of subschemas: the map KEYS are structural identifiers
     * (property names, `$defs` names, pattern strings) and must be preserved verbatim; only the
     * subschema VALUES recurse through the annotation strip.
     */
    private const array SCHEMA_SUBSCHEMA_MAP_KEYS = ['properties', '$defs', 'patternProperties', 'dependentSchemas'];

    /**
     * Keywords whose value is a list of subschemas.
     */
    private const array SCHEMA_SUBSCHEMA_LIST_KEYS = ['allOf', 'anyOf', 'oneOf', 'prefixItems'];

    /**
     * Keyword-aware structural view of a schema for inline-schema identity (design §2). Annotation
     * keywords are dropped only where they are schema annotations; recursion follows the JSON Schema
     * applicator keywords, so a real property literally named `description`/`title`/`example` keeps
     * its place in identity. `required` is order-normalised here (identity only, never in canonical
     * output) so member reordering does not fork the id (architecture N2). Non-applicator keyword
     * values (`type`, `enum`, `const`, `default`, numeric bounds, …) are data and pass through
     * untouched — their nested members are never treated as annotations.
     *
     * @param  array<mixed, mixed>  $schema
     * @return array<string, mixed>
     */
    private function stripForStructuralHash(array $schema): array
    {
        $out = [];

        foreach ($schema as $key => $value) {
            if (in_array($key, self::SCHEMA_ANNOTATION_KEYS, true)) {
                continue;
            }

            if ($key === 'required' && is_array($value)) {
                $out['required'] = $this->sortedRequired($value);

                continue;
            }

            if (in_array($key, self::SCHEMA_SUBSCHEMA_MAP_KEYS, true) && is_array($value)) {
                $out[(string) $key] = $this->stripSubschemaMap($value);

                continue;
            }

            if (in_array($key, self::SCHEMA_SUBSCHEMA_LIST_KEYS, true) && is_array($value)) {
                $out[(string) $key] = array_map(
                    fn (mixed $item): mixed => is_array($item) ? $this->stripForStructuralHash($item) : $item,
                    array_values($value),
                );

                continue;
            }

            if (in_array($key, self::SCHEMA_SUBSCHEMA_KEYS, true) && is_array($value)) {
                $out[(string) $key] = $this->stripForStructuralHash($value);

                continue;
            }

            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * Recurses the annotation strip through a map of subschemas without treating the map keys
     * (property/`$defs`/pattern names) as annotations.
     *
     * @param  array<mixed, mixed>  $map
     * @return array<string, mixed>
     */
    private function stripSubschemaMap(array $map): array
    {
        $out = [];

        foreach ($map as $name => $subschema) {
            $out[(string) $name] = is_array($subschema) ? $this->stripForStructuralHash($subschema) : $subschema;
        }

        return $out;
    }

    /**
     * Order-normalises a `required` list for identity: string members deduplicated and sorted by
     * code point. Applies to the identity-strip path only, so canonical output preserves order.
     *
     * @param  array<mixed, mixed>  $required
     * @return list<string>
     */
    private function sortedRequired(array $required): array
    {
        $names = [];
        foreach ($required as $name) {
            if (is_string($name)) {
                $names[$name] = true;
            }
        }

        $sorted = array_keys($names);
        usort($sorted, $this->compareKeys(...));

        return $sorted;
    }
}
