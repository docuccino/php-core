<?php

declare(strict_types=1);

use Docuccino\Core\Canonical\Canonicalizer;
use Docuccino\Core\SpecValidation\Validator;

/**
 * The UIR schema enumerates every member it accepts and every member order it publishes by hand,
 * and a hand-written list goes short in silence: the Path Item Object shipped with no word for OAS
 * 3.2's `additionalOperations` and the Components Object none for `mediaTypes`, so the schema
 * rejected a document the emitters produce. These read the two sources of truth rather than a third
 * hand-written copy — the OAS 3.2 meta-schema for WHICH members an object has, the canonicaliser
 * for WHERE each one sits.
 */

/**
 * A JSON file as an array.
 *
 * @return array<string, mixed>
 */
$readJson = static function (string $path): array {
    $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    /** @var array<string, mixed> $decoded */
    return $decoded;
};

/**
 * One object node of a schema, addressed by its `$defs` name — `''` names the document itself.
 *
 * @param  array<string, mixed>  $schema
 * @return array<string, mixed>
 */
$objectNode = static function (array $schema, string $name): array {
    if ($name === '') {
        return $schema;
    }

    $defs = $schema['$defs'] ?? null;
    $node = is_array($defs) ? ($defs[$name] ?? null) : null;

    /** @var array<string, mixed> $node */
    $node = is_array($node) ? $node : [];

    return $node;
};

/**
 * The members an object node defines, in the order it declares them.
 *
 * @param  array<string, mixed>  $node
 * @return list<string>
 */
$memberNames = static function (array $node): array {
    $properties = $node['properties'] ?? null;

    return is_array($properties) ? array_map(strval(...), array_keys($properties)) : [];
};

/**
 * The member order an object node publishes as `x-canonicalOrder`.
 *
 * @param  array<string, mixed>  $node
 * @return list<string>
 */
$publishedOrder = static function (array $node): array {
    $order = $node['x-canonicalOrder'] ?? null;

    return is_array($order) ? array_values(array_map(strval(...), $order)) : [];
};

it('spells every member OpenAPI 3.2 defines on each object it models', function () use ($readJson, $objectNode, $memberNames): void {
    $uir = $readJson(Validator::defaultSchemaPath());
    $oas = $readJson(dirname(__DIR__).'/Fixtures/openapi-v3.2.schema.json');

    // Each object the UIR schema models, under the name OAS 3.2 gives the same object.
    $objects = [
        '' => '',
        'info' => 'info',
        'server' => 'server',
        'tag' => 'tag',
        'pathItem' => 'path-item',
        'operation' => 'operation',
        'parameter' => 'parameter',
        'response' => 'response',
        'components' => 'components',
    ];

    // The Document Object members the UIR document has no position for. Nothing in the product
    // writes either and the canonicaliser has no slot to put them in, so closing the document
    // against them is a decision rather than an oversight — named here so a THIRD member OAS adds
    // fails instead of joining them quietly.
    $unmodelled = ['' => ['$self', 'externalDocs']];

    $compared = [];
    $short = [];

    foreach ($objects as $ours => $theirs) {
        $theirMembers = $memberNames($objectNode($oas, $theirs));
        $compared = [...$compared, ...$theirMembers];

        $missing = array_values(array_diff($theirMembers, $memberNames($objectNode($uir, $ours)), $unmodelled[$ours] ?? []));

        if ($missing !== []) {
            $short[$ours === '' ? 'document' : $ours] = $missing;
        }
    }

    // A diff over nothing is a diff that passes forever: prove the meta-schema was read and that
    // the two members this guard exists for were among what it compared.
    expect(count($compared))->toBeGreaterThanOrEqual(70)
        ->and($compared)->toContain('additionalOperations')
        ->and($compared)->toContain('mediaTypes')
        ->and($short)->toBe([]);
});

it('publishes a member order naming exactly the members it defines', function () use ($readJson, $memberNames, $publishedOrder): void {
    $uir = $readJson(Validator::defaultSchemaPath());
    $defs = $uir['$defs'] ?? [];

    // The Schema Object is the one exception and stays one: `additionalProperties: true` lets every
    // JSON Schema keyword through, so its published order names keywords it does not model.
    $nodes = ['' => $uir];
    foreach (is_array($defs) ? $defs : [] as $name => $def) {
        if (is_array($def) && $name !== 'schema') {
            /** @var array<string, mixed> $def */
            $nodes[(string) $name] = $def;
        }
    }

    $checked = 0;
    $disagreed = [];

    foreach ($nodes as $name => $node) {
        $order = $publishedOrder($node);

        if ($order === []) {
            continue;
        }

        $checked++;
        $members = $memberNames($node);
        sort($order);
        sort($members);

        if ($order !== $members) {
            $disagreed[$name === '' ? 'document' : $name] = [
                'order only' => array_values(array_diff($order, $members)),
                'members only' => array_values(array_diff($members, $order)),
            ];
        }
    }

    expect($checked)->toBeGreaterThanOrEqual(18)
        ->and($disagreed)->toBe([]);
});

it('publishes the member order the canonicaliser actually produces', function () use ($readJson, $objectNode, $publishedOrder): void {
    $extension = ['id' => 'op:v1:0123456789abcdef'];

    $response = [
        'x-docuccino' => $extension,
        'links' => ['l' => ['operationId' => 'o']],
        'content' => ['application/json' => ['schema' => ['type' => 'object']]],
        'headers' => ['H' => ['schema' => ['type' => 'string']]],
        'description' => 'd',
        'summary' => 's',
        '$ref' => '#/components/responses/R',
    ];

    $operation = [
        'x-docuccino' => $extension,
        'callbacks' => ['cb' => ['/c' => ['get' => ['responses' => ['200' => ['description' => 'x']]]]]],
        'responses' => ['200' => $response],
        'requestBody' => ['content' => ['application/json' => ['schema' => ['type' => 'object']]]],
        'parameters' => [['name' => 'a', 'in' => 'query', 'schema' => ['type' => 'string']]],
        'servers' => [['url' => 'https://x.test']],
        'security' => [['Key' => []]],
        'tags' => ['T'],
        'deprecated' => true,
        'externalDocs' => ['url' => 'https://d.test'],
        'description' => 'd',
        'summary' => 's',
        'operationId' => 'o',
    ];

    $parameter = [
        'x-docuccino' => $extension,
        'content' => ['application/json' => ['schema' => ['type' => 'string']]],
        'examples' => ['e' => ['value' => 1]],
        'example' => 1,
        'schema' => ['type' => 'string'],
        'allowReserved' => false,
        'explode' => true,
        'style' => 'form',
        'allowEmptyValue' => false,
        'deprecated' => false,
        'required' => true,
        'description' => 'd',
        'in' => 'query',
        'name' => 'n',
        '$ref' => '#/components/parameters/P',
    ];

    $pathItem = [
        'x-docuccino' => $extension,
        'additionalOperations' => ['PURGE' => ['responses' => ['204' => ['description' => 'x']]]],
        'query' => $operation, 'trace' => $operation, 'patch' => $operation, 'head' => $operation,
        'options' => $operation, 'delete' => $operation, 'post' => $operation, 'put' => $operation,
        'get' => $operation,
        'parameters' => [$parameter],
        'servers' => [['url' => 'https://x.test']],
        'description' => 'd',
        'summary' => 's',
        '$ref' => '#/components/pathItems/P',
    ];

    $schema = [
        'x-docuccino' => $extension,
        'not' => ['type' => 'null'],
        'oneOf' => [['type' => 'string']],
        'anyOf' => [['type' => 'string']],
        'allOf' => [['type' => 'object']],
        'additionalProperties' => false,
        'required' => ['a'],
        'properties' => ['a' => ['type' => 'string']],
        'prefixItems' => [['type' => 'string']],
        'items' => ['type' => 'string'],
        'default' => 'a',
        'const' => 'a',
        'enum' => ['a'],
        'format' => 'uuid',
        'type' => 'object',
        '$ref' => '#/components/schemas/Other',
    ];

    // Every node is written in REVERSE of the order the schema publishes, so passing means the
    // canonicaliser imposed that order rather than preserving the order it was handed.
    $document = [
        'x-docuccino' => ['document' => ['id' => 'doc:probe']],
        'components' => [
            'x-docuccino' => $extension,
            'pathItems' => ['P' => $pathItem],
            'callbacks' => ['C' => ['/c' => ['get' => $operation]]],
            'links' => ['L' => ['operationId' => 'o']],
            'securitySchemes' => ['Key' => ['type' => 'apiKey']],
            'mediaTypes' => ['M' => ['schema' => ['type' => 'object']]],
            'headers' => ['H' => ['schema' => ['type' => 'string']]],
            'requestBodies' => ['B' => ['content' => []]],
            'examples' => ['E' => ['value' => 1]],
            'parameters' => ['P' => $parameter],
            'responses' => ['R' => $response],
            'schemas' => ['S' => $schema],
        ],
        'webhooks' => ['w' => $pathItem],
        'paths' => ['/p' => $pathItem],
        'tags' => [['x-docuccino' => $extension, 'kind' => 'nav', 'parent' => 'x', 'externalDocs' => ['url' => 'https://d.test'], 'description' => 'd', 'summary' => 's', 'name' => 'T']],
        'security' => [['Key' => []]],
        'servers' => [['x-docuccino' => $extension, 'variables' => ['v' => ['default' => 'a']], 'name' => 'production', 'description' => 'd', 'url' => 'https://x.test']],
        'info' => ['x-docuccino' => $extension, 'version' => '1.0.0', 'license' => ['name' => 'MIT'], 'contact' => ['name' => 'c'], 'termsOfService' => 't', 'description' => 'd', 'summary' => 's', 'title' => 'T'],
        'jsonSchemaDialect' => 'https://spec.openapis.org/oas/3.2/dialect/base',
        'openapi' => '3.2.0',
        'uir' => '1.0.0',
        '$schema' => 'https://spec.docuccino.app/uir/1.0/schema.json',
    ];

    // Where each object type sits in the probe. Objects, not maps: a Media Type or a Header has no
    // `$defs` of its own to publish an order, so there is nothing here to compare it against.
    $where = [
        '' => [],
        'info' => ['info'],
        'server' => ['servers', 0],
        'tag' => ['tags', 0],
        'pathItem' => ['paths', '/p'],
        'operation' => ['paths', '/p', 'get'],
        'parameter' => ['paths', '/p', 'parameters', 0],
        'response' => ['paths', '/p', 'get', 'responses', '200'],
        'components' => ['components'],
        'schema' => ['components', 'schemas', 'S'],
    ];

    $uir = $readJson(Validator::defaultSchemaPath());
    $canonical = json_decode((string) json_encode((new Canonicalizer)->canonicalize($document)), true, flags: JSON_THROW_ON_ERROR);

    $disagreed = [];
    $unprobed = [];

    foreach ($where as $name => $pointer) {
        $published = $publishedOrder($objectNode($uir, $name));

        $written = $document;
        $produced = $canonical;
        foreach ($pointer as $step) {
            $written = is_array($written) ? ($written[$step] ?? null) : null;
            $produced = is_array($produced) ? ($produced[$step] ?? null) : null;
        }

        $writtenKeys = is_array($written) ? array_map(strval(...), array_keys($written)) : [];
        $producedKeys = is_array($produced) ? array_map(strval(...), array_keys($produced)) : [];

        // The probe carries every published member or it proves nothing about the missing ones.
        $missing = array_values(array_diff($published, $writtenKeys));
        if ($missing !== []) {
            $unprobed[$name === '' ? 'document' : $name] = $missing;
        }

        if (array_values(array_intersect($producedKeys, $published)) !== $published) {
            $disagreed[$name === '' ? 'document' : $name] = ['published' => $published, 'produced' => $producedKeys];
        }
    }

    expect($unprobed)->toBe([])
        ->and($disagreed)->toBe([]);
});
