<?php

declare(strict_types=1);

namespace Docuccino\Core\Canonical;

use Docuccino\Core\Document\PathItem;
use Docuccino\Core\Draft\SchemaKeywords;
use Docuccino\Core\Support\Json;
use Docuccino\Core\Support\JsonValue;
use stdClass;

/**
 * Normative canonicalisation: fixed member order per object type, map keys sorted by Unicode
 * code point, fixed HTTP method order, parameters sorted by (in-rank, name), declaration-order
 * dedup for tags/security/enum. Spec: docs/design/uir-and-extensions.md §3.
 *
 * Handlers take `mixed` and pass malformed values through untouched, so canonicalisation is
 * total. Empty object-typed members become {@see stdClass} so the serializer writes `{}` not
 * `[]`; `x-*` members other than `x-docuccino` pass through verbatim, since the shape of somebody
 * else's vocabulary is theirs to state and {@see JsonValue} is what preserves it on the way in.
 *
 * @internal
 */
final class Canonicalizer
{
    private const array PARAMETER_IN_RANK = ['path' => 0, 'query' => 1, 'header' => 2, 'cookie' => 3];

    /**
     * Every member an object that is NOT a Schema Object reads as one, and the position it reads it at.
     * The handler maps below are BUILT from this ({@see schemaSlots()}), so the table is the set of outer
     * schema slots rather than a description of one: a slot with no line here is not read as a schema at
     * all, which is what makes a guard reading this table bite instead of agreeing with itself.
     *
     * @var array<string, array<string, string>>
     */
    public const array SCHEMA_SLOTS = [
        'components' => ['schemas' => SchemaKeywords::POSITION_SCHEMA_MAP],
        'header' => ['schema' => SchemaKeywords::POSITION_SCHEMA],
        'mediaType' => ['schema' => SchemaKeywords::POSITION_SCHEMA, 'itemSchema' => SchemaKeywords::POSITION_SCHEMA],
        'parameter' => ['schema' => SchemaKeywords::POSITION_SCHEMA],
    ];

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
     * Structural view of a schema for inline-schema identity: descriptions, examples and
     * `x-docuccino` stripped recursively, then canonicalised.
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

    /**
     * One object's schema-slot handlers, spliced into its member map where that object's normative
     * member order puts them.
     *
     * @return array<string, callable(mixed): mixed>
     */
    private function schemaSlots(string $object): array
    {
        $handlers = [];

        foreach (self::SCHEMA_SLOTS[$object] as $member => $position) {
            $handlers[$member] = fn (mixed $v): mixed => $this->subschema($position, $v);
        }

        return $handlers;
    }

    private function compareKeys(int|string $a, int|string $b): int
    {
        return strcmp((string) $a, (string) $b);
    }

    /**
     * `$residual` reads the members no handler names, where the node type still knows something
     * about them — a Schema Object does, from the keyword's position. Without one they are data.
     *
     * @param  array<mixed, mixed>  $node
     * @param  array<string, callable(mixed): mixed>  $handlers
     * @param  (callable(string, mixed): mixed)|null  $residual
     * @return array<string, mixed>
     */
    private function build(array $node, array $handlers, ?callable $residual = null): array
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
            // An `x-*` member is somebody else's vocabulary: verbatim, whatever shape it is in.
            $out[$key] = match (true) {
                str_starts_with($key, 'x-') => $value,
                $residual !== null => $residual($key, $value),
                default => $this->canonicalizeGeneric($value),
            };
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
            'name' => $this->keep(...),
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
            'summary' => $this->keep(...),
            'description' => $this->keep(...),
            'externalDocs' => $this->canonicalizeExternalDocs(...),
            'parent' => $this->keep(...),
            'kind' => $this->keep(...),
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

        // Decorated with a TOTAL key. `in` and `name` settle every parameter stated inline, but a
        // `{"$ref": …}` parameter states neither, so a list of them would all tie and keep the order
        // they arrived in — which is whatever built the list. The bytes break the remaining ties, and
        // two parameters with the same bytes are the same parameter.
        $keyed = [];
        foreach ($node as $parameter) {
            $canonical = $this->canonicalizeParameter($parameter);
            $keyed[] = [[$this->parameterRank($canonical), $this->parameterName($canonical), Json::stable($canonical)], $canonical];
        }

        usort($keyed, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return array_column($keyed, 1);
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
            ...$this->schemaSlots('parameter'),
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
            ...$this->schemaSlots('header'),
            'example' => $this->keep(...),
            'examples' => fn (mixed $v): mixed => $this->sortedMap($v, $this->canonicalizeExample(...)),
            'content' => fn (mixed $v): mixed => $this->sortedMap($v, $this->canonicalizeMediaType(...)),
        ]));
    }

    /**
     * @return array<string, mixed>|stdClass
     */
    /**
     * All three encoding slots read an Encoding Object the same generic way: an Encoding Object carries
     * no schema of its own, so nothing here turns on knowing one, and reading `itemEncoding` any
     * differently from `encoding` would publish two member orders for one kind of object.
     *
     * @return array<string, mixed>|stdClass
     */
    private function canonicalizeMediaType(mixed $node): array|stdClass
    {
        return $this->object($node, fn (array $media) => $this->build($media, [
            'x-docuccino' => $this->canonicalizeDocuccino(...),
            'description' => $this->keep(...),
            ...$this->schemaSlots('mediaType'),
            'example' => $this->keep(...),
            'examples' => fn (mixed $v): mixed => $this->sortedMap($v, $this->canonicalizeExample(...)),
            'encoding' => fn (mixed $v): mixed => $this->sortedMap($v, $this->canonicalizeGeneric(...)),
            'prefixEncoding' => fn (mixed $v): mixed => $this->mapList($v, $this->canonicalizeGeneric(...)),
            'itemEncoding' => $this->canonicalizeGeneric(...),
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
            // `dataValue` is the application's data exactly as `value` is; `serializedValue` is its
            // wire form, a string.
            'dataValue' => $this->canonicalizeGeneric(...),
            'serializedValue' => $this->keep(...),
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
            ...$this->schemaSlots('components'),
            'responses' => fn (mixed $v): mixed => $this->sortedMap($v, $this->canonicalizeResponse(...)),
            'parameters' => fn (mixed $v): mixed => $this->sortedMap($v, $this->canonicalizeParameter(...)),
            'examples' => fn (mixed $v): mixed => $this->sortedMap($v, $this->canonicalizeExample(...)),
            'requestBodies' => fn (mixed $v): mixed => $this->sortedMap($v, $this->canonicalizeRequestBody(...)),
            'headers' => fn (mixed $v): mixed => $this->sortedMap($v, $this->canonicalizeHeader(...)),
            'mediaTypes' => fn (mixed $v): mixed => $this->sortedMap($v, $this->canonicalizeMediaType(...)),
            'securitySchemes' => fn (mixed $v): mixed => $this->sortedMap($v, $this->canonicalizeGeneric(...)),
            'links' => fn (mixed $v): mixed => $this->sortedMap($v, $this->canonicalizeGeneric(...)),
            'callbacks' => fn (mixed $v): mixed => $this->sortedMap($v, fn (mixed $cb): mixed => $this->sortedMap($cb, $this->canonicalizePathItem(...))),
            'pathItems' => fn (mixed $v): mixed => $this->sortedMap($v, $this->canonicalizePathItem(...)),
            'x-docuccino' => $this->canonicalizeDocuccino(...),
        ]));
    }

    /**
     * The normative member order of a Schema Object. Order is the one thing about a keyword that is
     * a choice rather than a fact, so it is stated here; how each member canonicalises is derived
     * from the keyword's own contract in {@see SchemaKeywords}. A keyword missing from this list
     * still canonicalises correctly — it sorts into the trailing run with the other unlisted
     * members — so going stale costs a member its place and never its shape.
     *
     * @var list<string>
     */
    private const array SCHEMA_ORDER = [
        'x-docuccino',
        '$ref',
        '$id',
        '$anchor',
        '$defs',
        'definitions',
        'title',
        'description',
        'type',
        'format',
        'enum',
        'const',
        'default',
        'multipleOf',
        'maximum',
        'exclusiveMaximum',
        'minimum',
        'exclusiveMinimum',
        'maxLength',
        'minLength',
        'pattern',
        'contentEncoding',
        'contentMediaType',
        'contentSchema',
        'items',
        'prefixItems',
        'additionalItems',
        'contains',
        'unevaluatedItems',
        'maxItems',
        'minItems',
        'uniqueItems',
        'properties',
        'required',
        'additionalProperties',
        'patternProperties',
        'propertyNames',
        'unevaluatedProperties',
        'maxProperties',
        'minProperties',
        'dependentRequired',
        'dependentSchemas',
        'allOf',
        'anyOf',
        'oneOf',
        'not',
        'if',
        'then',
        'else',
        'discriminator',
        'externalDocs',
        'example',
        'examples',
        'readOnly',
        'writeOnly',
        'deprecated',
        'nullable',
    ];

    /**
     * @return array<string, mixed>|stdClass
     */
    private function canonicalizeSchema(mixed $node): array|stdClass
    {
        $handlers = [];
        foreach (self::SCHEMA_ORDER as $keyword) {
            $handlers[$keyword] = fn (mixed $v): mixed => $this->schemaMember($keyword, $v);
        }

        return $this->object($node, fn (array $schema) => $this->build($schema, $handlers, $this->schemaResidual(...)));
    }

    /**
     * One member {@see SCHEMA_ORDER} names, canonicalised for the position its keyword sits at.
     * Everything else is data the schema states about instances, or prose about it.
     */
    private function schemaMember(string $keyword, mixed $value): mixed
    {
        $position = SchemaKeywords::positionOf($keyword);

        if ($position !== null) {
            return $this->subschema($position, $value);
        }

        return match ($keyword) {
            'x-docuccino' => $this->canonicalizeDocuccino($value),
            'externalDocs' => $this->canonicalizeExternalDocs($value),
            'enum' => $this->canonicalizeValueList($value),
            'required' => $this->canonicalizeStringList($value),
            'const', 'default', 'discriminator', 'example', 'examples' => $this->canonicalizeGeneric($value),
            default => $this->keep($value),
        };
    }

    /**
     * A member no ordering names. Its position still decides its shape — which is what keeps a
     * keyword the order list has not caught up with valid rather than merely deterministic — and
     * anything with no position is data, sorted like any other unknown member.
     */
    private function schemaResidual(string $keyword, mixed $value): mixed
    {
        $position = SchemaKeywords::positionOf($keyword);

        return $position === null ? $this->canonicalizeGeneric($value) : $this->subschema($position, $value);
    }

    /**
     * A keyword's value read as the subschemas its position says are there. All three schema-carrying
     * positions read each subschema the SAME way ({@see subschemaValue()}): a position tells us where
     * a subschema sits, never what may stand in one.
     */
    private function subschema(string $position, mixed $value): mixed
    {
        return match ($position) {
            SchemaKeywords::POSITION_SCHEMA => $this->subschemaValue($value),
            SchemaKeywords::POSITION_SCHEMA_MAP => $this->sortedMap($value, $this->subschemaValue(...)),
            SchemaKeywords::POSITION_SCHEMA_LIST => $this->mapList($value, $this->subschemaValue(...)),
            SchemaKeywords::POSITION_STRING_LIST_MAP => $this->sortedMap($value, $this->canonicalizeStringList(...)),
            default => $this->canonicalizeGeneric($value),
        };
    }

    /**
     * ONE Schema Object, wherever it sits — inside a schema, and equally at the slots a schema hangs off
     * something that is not one. A boolean is published as written, since it is a schema at every 2020-12
     * subschema position and the load-bearing value there; anything that is no schema at all becomes `{}`,
     * vague and valid beating a document no validator accepts. That second arm is deliberately silent,
     * unlike every other widening — design doc §1 "The empty-object invariant" for that and for why the
     * position rather than the value answers.
     */
    private function subschemaValue(mixed $value): mixed
    {
        return is_bool($value) ? $value : $this->canonicalizeSchema($value);
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
     * Children keep declaration order — nav order is meaningful, and the compiler already
     * emits it deterministically.
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
        $index = 0;
        $sequential = true;
        foreach ($node as $key => $value) {
            $sequential = $sequential && $key === $index;
            $index++;
            $out[(string) $key] = $child($value);
        }

        // PHP re-coerces a numeric-string key straight back to an int, so a map whose keys happen to be
        // `0..n` — `properties` synthesised from a tuple's indices — would serialise as a JSON ARRAY.
        // An object-valued member is an object whatever its keys look like.
        return $sequential ? (object) $out : $out;
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
            // As in `build()`: an `x-*` member is somebody else's vocabulary, verbatim.
            $out[$key] = str_starts_with($key, 'x-') ? $value : $this->canonicalizeGeneric($value);
        }

        return $out;
    }

    /** Prose that must not change a schema's structural `sch:` id. Stripped only in annotation position. */
    private const array SCHEMA_ANNOTATION_KEYS = ['description', 'title', 'example', 'examples', 'x-docuccino'];

    /**
     * Keyword-aware structural view of a schema for inline-schema identity. Recursion follows the
     * subschema positions {@see SchemaKeywords} names, so a property literally named
     * `description`/`title`/`example` still counts towards identity. `required` is order-normalised
     * here — identity only, never in canonical output — so reordering members can't fork the id.
     * Everything else (`type`, `enum`, `const`, bounds, …) is data and passes through untouched.
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

            $position = SchemaKeywords::positionOf((string) $key);

            $out[(string) $key] = match (true) {
                ! is_array($value) => $value,
                $position === SchemaKeywords::POSITION_SCHEMA_MAP => $this->stripSubschemaMap($value),
                $position === SchemaKeywords::POSITION_SCHEMA_LIST => array_map(
                    fn (mixed $item): mixed => is_array($item) ? $this->stripForStructuralHash($item) : $item,
                    array_values($value),
                ),
                $position === SchemaKeywords::POSITION_SCHEMA => $this->stripForStructuralHash($value),
                default => $value,
            };
        }

        return $out;
    }

    /**
     * Recurses the annotation strip into subschema values, leaving the map's own keys
     * (property/`$defs`/pattern names) alone.
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
     * Dedups and code-point-sorts `required` for identity only — canonical output keeps order.
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
