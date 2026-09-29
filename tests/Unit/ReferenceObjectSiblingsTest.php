<?php

declare(strict_types=1);

use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\Formats;
use Docuccino\Core\SpecValidation\OpenApiMetaSchema;
use Docuccino\Core\Support\JsonPointer;

/**
 * A Reference Object, at every position OpenAPI lets one stand, carrying an identity.
 *
 * Every version's text says a Reference Object "cannot be extended with additional properties": 3.1
 * and 3.2 name `summary` and `description` as its only members beside `$ref`, and 3.0 none at all. So
 * an export that keeps ids — `docuccino:export`'s default — owes the id to the node the reference
 * names, never to the reference, or a strict reader refuses the whole document. A Schema Object is
 * the exception from 3.1 on, because it is JSON Schema and takes siblings.
 *
 * Each row is a position, named by the meta-schema definition that declares it, so the set of rows is
 * held to the vendored files below rather than to anyone's memory of the spec.
 */

/**
 * Where each row puts its reference: the token path into the document, the component it names, and — per
 * version that reads the position as a Reference Object rather than a node of its own — the site in that
 * version's vendored meta-schema that admits one there: the node naming `*-or-reference` (3.1, 3.2), or
 * the one whose `oneOf` names `Reference` (3.0).
 *
 * @return array<string, array{list<string>, string, array<string, string>}>
 */
function referencePositionRows(): array
{
    $operation = ['paths', '/things/{thing}', 'post'];
    $ok = [...$operation, 'responses', '200'];
    $body = [...$operation, 'requestBody', 'content', 'application/json'];
    $schema = [...$ok, 'content', 'application/json', 'schema'];

    // One site at every version, spelled in each file's own vocabulary.
    $all = static fn (string $defs, string $definitions): array => [
        'openapi-3.2' => '/$defs/'.$defs,
        'openapi-3.1' => '/$defs/'.$defs,
        'openapi-3.0' => '/definitions/'.$definitions,
    ];
    $component = static fn (string $bucket): array => $all(
        'components/properties/'.$bucket.'/additionalProperties',
        'Components/properties/'.$bucket.'/patternProperties/^[a-zA-Z0-9\.\-_]+$',
    );
    $example = static fn (string $owner): array => $all('examples/properties/examples/additionalProperties', $owner.'/properties/examples/additionalProperties');
    $threeZero = static fn (string $definitions): array => ['openapi-3.0' => '/definitions/'.$definitions];
    // 3.2 states an operation's and a path item's parameter list once, as `parameters`.
    $parameters = static fn (string $owner): array => [
        'openapi-3.2' => '/$defs/parameters/items',
        'openapi-3.1' => '/$defs/'.$owner.'/properties/parameters/items',
        'openapi-3.0' => '/definitions/'.ucfirst($owner === 'path-item' ? 'pathItem' : $owner).'/properties/parameters/items',
    ];

    return [
        'an operation response' => [[...$operation, 'responses', '404'], '#/components/responses/R', $all('responses/patternProperties/^[1-5](?:[0-9]{2}|XX)$', 'Responses/patternProperties/^[1-5](?:\d{2}|XX)$')],
        'a default response' => [[...$operation, 'responses', 'default'], '#/components/responses/R', $all('responses/properties/default', 'Responses/properties/default')],
        'a component response' => [['components', 'responses', 'R2'], '#/components/responses/R', $component('responses')],
        'an operation parameter' => [[...$operation, 'parameters', '1'], '#/components/parameters/P', $parameters('operation')],
        'a path item parameter' => [['paths', '/things/{thing}', 'parameters', '0'], '#/components/parameters/Q', $parameters('path-item')],
        'a component parameter' => [['components', 'parameters', 'P2'], '#/components/parameters/P', $component('parameters')],
        'a request body' => [[...$operation, 'requestBody'], '#/components/requestBodies/B', $all('operation/properties/requestBody', 'Operation/properties/requestBody')],
        'a component request body' => [['components', 'requestBodies', 'B2'], '#/components/requestBodies/B', $component('requestBodies')],
        'a response header' => [[...$ok, 'headers', 'X-B'], '#/components/headers/H', $all('response/properties/headers/additionalProperties', 'Response/properties/headers/additionalProperties')],
        'an encoding header' => [[...$body, 'encoding', 'a', 'headers', 'X-F'], '#/components/headers/H', $all('encoding/properties/headers/additionalProperties', 'Encoding/properties/headers/additionalProperties')],
        // 3.2's positional encodings, which the downlevels drop with the rest of what they cannot state.
        'a prefix encoding header' => [[...$operation, 'requestBody', 'content', 'multipart/mixed', 'prefixEncoding', '0', 'headers', 'X-P'], '#/components/headers/H', ['openapi-3.2' => '/$defs/encoding/properties/headers/additionalProperties']],
        'an item encoding header' => [[...$operation, 'requestBody', 'content', 'multipart/mixed', 'itemEncoding', 'headers', 'X-I'], '#/components/headers/H', ['openapi-3.2' => '/$defs/encoding/properties/headers/additionalProperties']],
        'a component header' => [['components', 'headers', 'H2'], '#/components/headers/H', $component('headers')],
        'a media type example' => [[...$body, 'examples', 'r'], '#/components/examples/E', $example('MediaType')],
        'a parameter example' => [[...$operation, 'parameters', '0', 'examples', 'r'], '#/components/examples/E', $example('Parameter')],
        'a header example' => [[...$ok, 'headers', 'X-A', 'examples', 'r'], '#/components/examples/E', $example('Header')],
        'a component example' => [['components', 'examples', 'E2'], '#/components/examples/E', $component('examples')],
        'a link' => [[...$ok, 'links', 'other'], '#/components/links/L', $all('response/properties/links/additionalProperties', 'Response/properties/links/additionalProperties')],
        'a component link' => [['components', 'links', 'L2'], '#/components/links/L', $component('links')],
        'a callback' => [[...$operation, 'callbacks', 'other'], '#/components/callbacks/C', $all('operation/properties/callbacks/additionalProperties', 'Operation/properties/callbacks/additionalProperties')],
        'a component callback' => [['components', 'callbacks', 'C2'], '#/components/callbacks/C', $component('callbacks')],
        'a component security scheme' => [['components', 'securitySchemes', 'K2'], '#/components/securitySchemes/K', $component('securitySchemes')],
        // 3.2's own bucket; the downlevels inline what it names, so only 3.2 publishes the reference.
        'a response media type' => [[...$ok, 'content', 'application/xml'], '#/components/mediaTypes/M', ['openapi-3.2' => '/$defs/content/additionalProperties']],
        'a component media type' => [['components', 'mediaTypes', 'M2'], '#/components/mediaTypes/M', ['openapi-3.2' => '/$defs/components/properties/mediaTypes/additionalProperties']],
        // A Path Item states `$ref` as a field of its own — but 3.1 maps webhooks, shared path items and a
        // callback's expressions to "Path Item Object | Reference Object", so there a `$ref` is a reference.
        'a webhook' => [['webhooks', 'hook'], '#/components/pathItems/PI', ['openapi-3.1' => '/properties/webhooks/additionalProperties']],
        'a component path item' => [['components', 'pathItems', 'PI2'], '#/components/pathItems/PI', ['openapi-3.1' => '/$defs/components/properties/pathItems/additionalProperties']],
        'a callback path item' => [[...$operation, 'callbacks', 'done', '{$request.body#/url}'], '#/components/pathItems/PI', ['openapi-3.1' => '/$defs/callbacks/additionalProperties']],
        // Before 3.1 adopted JSON Schema whole, a Schema Object's `$ref` was a Reference Object too, at every
        // position one stands.
        'a component schema' => [['components', 'schemas', 'S2'], '#/components/schemas/S', $threeZero('Components/properties/schemas/patternProperties/^[a-zA-Z0-9\.\-_]+$')],
        'a media type schema' => [$schema, '#/components/schemas/S', $threeZero('MediaType/properties/schema')],
        'a parameter schema' => [[...$operation, 'parameters', '0', 'schema'], '#/components/schemas/S', $threeZero('Parameter/properties/schema')],
        'a header schema' => [[...$ok, 'headers', 'X-A', 'schema'], '#/components/schemas/S', $threeZero('Header/properties/schema')],
        'a property schema' => [[...$schema, 'properties', 'thing'], '#/components/schemas/S', $threeZero('Schema/properties/properties/additionalProperties')],
        'an additional properties schema' => [[...$schema, 'additionalProperties'], '#/components/schemas/S', $threeZero('Schema/properties/additionalProperties')],
        'an items schema' => [[...$schema, 'properties', 'list', 'items'], '#/components/schemas/S', $threeZero('Schema/properties/items')],
        'a not schema' => [[...$schema, 'not'], '#/components/schemas/S', $threeZero('Schema/properties/not')],
        'an allOf branch' => [[...$schema, 'allOf', '0'], '#/components/schemas/S', $threeZero('Schema/properties/allOf/items')],
        'a oneOf branch' => [[...$schema, 'oneOf', '0'], '#/components/schemas/S', $threeZero('Schema/properties/oneOf/items')],
        'an anyOf branch' => [[...$schema, 'anyOf', '0'], '#/components/schemas/S', $threeZero('Schema/properties/anyOf/items')],
    ];
}

/**
 * Every site $format's vendored meta-schema admits a Reference Object at, by the pointer
 * {@see referencePositionRows()} names it with.
 *
 * @return list<string>
 */
function referencePositionSites(string $format): array
{
    $threeZero = $format === 'openapi-3.0';
    $sites = [];

    $walk = static function (mixed $node, string $pointer) use (&$walk, &$sites, $threeZero): void {
        if (! $node instanceof stdClass && ! is_array($node)) {
            return;
        }

        if ($node instanceof stdClass) {
            $ref = $node->{'$ref'} ?? null;
            if (! $threeZero && is_string($ref) && str_ends_with($ref, '-or-reference')) {
                $sites[] = $pointer;
            }

            foreach (is_array($node->oneOf ?? null) ? $node->oneOf : [] as $branch) {
                if ($threeZero && $branch instanceof stdClass && ($branch->{'$ref'} ?? null) === '#/definitions/Reference') {
                    $sites[] = $pointer;
                }
            }
        }

        foreach ((array) $node as $key => $child) {
            $walk($child, JsonPointer::child($pointer, (string) $key));
        }
    };
    $walk(OpenApiMetaSchema::decode($format), '');

    $sites = array_values(array_unique($sites));
    sort($sites);

    return $sites;
}

/** @return array<string, mixed> every node the rows point into, inline, and every component they name */
function referencePositionDocument(): array
{
    $schema = ['type' => 'string'];
    $examples = ['e' => ['value' => 'one']];

    return [
        'openapi' => '3.2.0',
        'info' => ['title' => 'API', 'version' => '1.0.0'],
        'paths' => ['/things/{thing}' => [
            'parameters' => [['name' => 'thing', 'in' => 'path', 'required' => true, 'schema' => $schema]],
            'post' => [
                'x-docuccino' => ['id' => 'op:v1:things-post'],
                'operationId' => 'things.post',
                'parameters' => [['name' => 'q', 'in' => 'query', 'schema' => $schema, 'examples' => $examples]],
                'requestBody' => ['content' => ['application/json' => [
                    'schema' => ['type' => 'object', 'properties' => ['a' => $schema]],
                    'examples' => $examples,
                    'encoding' => ['a' => ['headers' => ['X-E' => ['schema' => $schema]]]],
                ], 'multipart/mixed' => [
                    'itemSchema' => $schema,
                    'prefixEncoding' => [['contentType' => 'text/plain']],
                    'itemEncoding' => ['contentType' => 'text/plain'],
                ]]],
                'responses' => ['200' => [
                    'x-docuccino' => ['id' => 'res:v1:things-post-200'],
                    'description' => 'The thing.',
                    'headers' => ['X-A' => ['schema' => $schema, 'examples' => $examples]],
                    'links' => ['self' => ['operationId' => 'things.post']],
                    'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['id' => $schema]]]],
                ]],
                'callbacks' => ['done' => ['{$request.body#/url}' => ['post' => ['responses' => ['200' => ['description' => 'Received.']]]]]],
            ],
        ]],
        'components' => [
            'schemas' => ['S' => ['type' => 'object']],
            'responses' => ['R' => ['description' => 'Not found.']],
            'parameters' => [
                'P' => ['name' => 'p', 'in' => 'query', 'schema' => $schema],
                'Q' => ['name' => 'thing', 'in' => 'path', 'required' => true, 'schema' => $schema],
            ],
            'requestBodies' => ['B' => ['content' => ['application/json' => ['schema' => ['type' => 'object']]]]],
            'headers' => ['H' => ['schema' => $schema]],
            'examples' => ['E' => ['value' => 'shared']],
            'links' => ['L' => ['operationId' => 'things.post']],
            'callbacks' => ['C' => ['{$request.body#/url}' => ['post' => ['responses' => ['200' => ['description' => 'Received.']]]]]],
            'securitySchemes' => ['K' => ['type' => 'apiKey', 'name' => 'key', 'in' => 'header']],
            'mediaTypes' => ['M' => ['schema' => ['type' => 'object']]],
            'pathItems' => ['PI' => ['post' => ['responses' => ['200' => ['description' => 'Received.']]]]],
        ],
    ];
}

/**
 * @param  array<string, mixed>  $document
 * @param  list<string>  $tokens
 * @return array<string, mixed>
 */
function referencePositionPlace(array $document, array $tokens, mixed $value): array
{
    $node = &$document;
    foreach ($tokens as $token) {
        if (! is_array($node)) {
            $node = [];
        }
        $node = &$node[$token];
    }
    $node = $value;
    unset($node);

    return $document;
}

/** The object at $tokens in a decoded graph, or null where the emission published nothing there. */
function referencePositionAt(stdClass $graph, array $tokens): ?stdClass
{
    $node = $graph;
    foreach ($tokens as $token) {
        $node = is_array($node) ? ($node[(int) $token] ?? null) : ($node instanceof stdClass ? ($node->{$token} ?? null) : null);
    }

    return $node instanceof stdClass ? $node : null;
}

it('keeps an exported reference to its pointer and prose, with the id on the node it names', function (array $tokens, string $ref, array $sites, string $format): void {
    $versions = array_keys($sites);
    $document = referencePositionPlace(referencePositionDocument(), $tokens, [
        'x-docuccino' => ['id' => 'use:v1:the-use-site'],
        '$ref' => $ref,
    ]);

    $result = Formats::emit($format, UirDocument::fromArray($document), (new EmitOptions)->withKeepIds());
    $graph = json_decode($result->output, flags: JSON_THROW_ON_ERROR);

    $invalid = array_filter($result->report->diagnostics, static fn ($d): bool => $d->code === 'document.openapi-invalid');
    expect(array_map(static fn ($d): string => $d->message, $invalid))->toBe([])
        ->and(OpenApiMetaSchema::referenceSiblingFindings($format, $graph))->toBe([]);

    $reference = referencePositionAt($graph, $tokens);
    if (in_array($format, $versions, true)) {
        // Published as a reference, and nothing else: the use site's id names no node of its own.
        expect($reference)->not->toBeNull()
            ->and(array_keys(get_object_vars($reference)))->toBe(['$ref']);
    }

    // Ids stay wherever OpenAPI lets a node carry one.
    $operation = referencePositionAt($graph, ['paths', '/things/{thing}', 'post']);
    expect($operation?->{'x-docuccino-id'})->toBe('op:v1:things-post')
        ->and($operation?->responses?->{'200'}?->{'x-docuccino-id'})->toBe('res:v1:things-post-200');
})->with(referencePositionRows())->with(['openapi-3.2', 'openapi-3.1', 'openapi-3.0']);

it('keeps the id beside a schema $ref from 3.1 on, where a Schema Object is JSON Schema', function (string $format): void {
    $tokens = ['paths', '/things/{thing}', 'post', 'responses', '200', 'content', 'application/json', 'schema', 'properties', 'thing'];
    $document = referencePositionPlace(referencePositionDocument(), $tokens, [
        'x-docuccino' => ['id' => 'sch:v1:the-use-site'],
        '$ref' => '#/components/schemas/S',
    ]);

    $graph = json_decode(Formats::emit($format, UirDocument::fromArray($document), (new EmitOptions)->withKeepIds())->output, flags: JSON_THROW_ON_ERROR);

    expect(get_object_vars(referencePositionAt($graph, $tokens) ?? new stdClass))
        ->toBe(['$ref' => '#/components/schemas/S', 'x-docuccino-id' => 'sch:v1:the-use-site'])
        ->and(OpenApiMetaSchema::findings($format, $graph))->toBe([]);
})->with(['openapi-3.2', 'openapi-3.1']);

it('publishes a callback stated as a reference as the reference', function (string $format): void {
    // A Callback Object maps runtime expressions to path items, so read as one, `$ref` became an
    // expression and its pointer an empty path item.
    $tokens = ['paths', '/things/{thing}', 'post', 'callbacks', 'other'];
    $document = referencePositionPlace(referencePositionDocument(), $tokens, ['$ref' => '#/components/callbacks/C']);

    $result = Formats::emit($format, UirDocument::fromArray($document), new EmitOptions);
    $graph = json_decode($result->output, flags: JSON_THROW_ON_ERROR);

    expect(get_object_vars(referencePositionAt($graph, $tokens) ?? new stdClass))->toBe(['$ref' => '#/components/callbacks/C'])
        ->and(OpenApiMetaSchema::findings($format, $graph))->toBe([]);
})->with(['openapi-3.2', 'openapi-3.1', 'openapi-3.0']);

/*
 * The guard, executed: a member beside a `$ref` written into the clean emission at each row's position
 * is found there, and only where the version reads that position as a Reference Object.
 */
it('refuses any member beside a reference that its version does not define', function (array $tokens, string $ref, array $sites, string $format): void {
    $versions = array_keys($sites);
    $document = referencePositionPlace(referencePositionDocument(), $tokens, ['$ref' => $ref]);
    $graph = json_decode(Formats::emit($format, UirDocument::fromArray($document), new EmitOptions)->output, flags: JSON_THROW_ON_ERROR);

    expect(OpenApiMetaSchema::referenceSiblingFindings($format, $graph))->toBe([]);

    $reference = referencePositionAt($graph, $tokens);
    if ($reference === null) {
        // The downlevel inlined what the reference named, so there is no reference left to extend.
        expect($versions)->not->toContain($format);

        return;
    }

    $reference->{'x-vendor'} = 'anything';
    $reference->summary = 'A summary.';
    $reference->description = 'A description.';

    $findings = OpenApiMetaSchema::referenceSiblingFindings($format, $graph);
    $members = array_map(static fn (string $finding): string => (string) preg_replace('~^.*carry "([^"]+)".*$~', '$1', $finding), $findings);

    expect($members)->toBe(match (true) {
        ! in_array($format, $versions, true) => [],
        // 3.0's Reference Object is `$ref` alone; 3.1 added the two prose members.
        $format === 'openapi-3.0' => ['description', 'summary', 'x-vendor'],
        default => ['x-vendor'],
    });
})->with(referencePositionRows())->with(['openapi-3.2', 'openapi-3.1', 'openapi-3.0']);

it('leaves a Link Object\'s request body alone, whatever it holds', function (): void {
    // `requestBody` and `parameters` in a Link Object are values for the linked operation — data.
    $tokens = ['paths', '/things/{thing}', 'post', 'responses', '200', 'links', 'self', 'requestBody'];
    $document = referencePositionPlace(referencePositionDocument(), $tokens, ['$ref' => '#/not/a/reference', 'x-vendor' => true]);

    foreach (['openapi-3.2', 'openapi-3.1', 'openapi-3.0'] as $format) {
        $graph = json_decode(Formats::emit($format, UirDocument::fromArray($document), new EmitOptions)->output, flags: JSON_THROW_ON_ERROR);

        expect(OpenApiMetaSchema::referenceSiblingFindings($format, $graph))->toBe([]);
    }
});

it('names a row for every site a vendored meta-schema admits a Reference Object at', function (string $format): void {
    // The rows are a hand-maintained full set, so they are held to the files that define the domain —
    // site by site, since one definition is admitted at several positions and one position's definition
    // differs between versions.
    $covered = [];
    foreach (referencePositionRows() as [, , $sites]) {
        if (isset($sites[$format])) {
            $covered[] = $sites[$format];
        }
    }

    $covered = array_values(array_unique($covered));
    sort($covered);

    $declared = referencePositionSites($format);

    expect(count($declared))->toBeGreaterThanOrEqual(15)
        ->and($covered)->toBe($declared);
})->with(['openapi-3.2', 'openapi-3.1', 'openapi-3.0']);
