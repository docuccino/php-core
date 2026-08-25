<?php

declare(strict_types=1);

use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Emit\OpenApi30DownlevelEmitter;

/**
 * The 3.0 downlevel walk decides what a node is from WHERE it sits, never from how its key is spelled.
 *
 * Every document here spells an application-chosen name — a status code, a component, a header — exactly
 * like a member the walk hands back untouched, and asserts the name changed nothing about how the thing
 * was read. `responses.default` is the one that shipped: the catch-all response, keyed like the schema
 * keyword whose value is user data, so a 3.0 export carried 3.1 dialect underneath it.
 */
describe('a name spelled like a fixed field', function (): void {
    /**
     * A convertible schema and what 3.0 makes of it, so each case below reads as one line.
     *
     * @return array{array<string, mixed>, array<string, mixed>}
     */
    $convertible = static fn (): array => [
        ['type' => ['string', 'null'], 'const' => 'x', 'exclusiveMinimum' => 3],
        ['type' => 'string', 'enum' => ['x'], 'minimum' => 3, 'exclusiveMinimum' => true, 'nullable' => true],
    ];

    it('downlevels the schema beneath it', function (string $case, callable $place, array $pointer) use ($convertible): void {
        [$schema, $expected] = $convertible();

        $result = (new OpenApi30DownlevelEmitter)->emit(UirDocument::fromArray([
            'uir' => '1.0.0',
            'openapi' => '3.2.0',
            'info' => ['title' => 'API', 'version' => '1.0.0'],
            ...$place($schema),
        ]));

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($result, true, flags: JSON_THROW_ON_ERROR);

        $node = $decoded;
        foreach ($pointer as $token) {
            expect($node)->toHaveKey($token);
            $node = $node[$token];
        }

        expect($node)->toBe($expected);
    })->with([
        // `responses.default` is the catch-all response, and its key is spelled like the schema keyword
        // whose value is user data. Reading the key as a position passed this whole subtree through.
        'the catch-all response' => [
            'the catch-all response',
            fn (array $schema): array => ['paths' => ['/a' => ['get' => ['responses' => ['default' => [
                'description' => 'Unexpected',
                'content' => ['application/json' => ['schema' => $schema]],
            ]]]]]],
            ['paths', '/a', 'get', 'responses', 'default', 'content', 'application/json', 'schema'],
        ],
        'a response component named default' => [
            'a response component named default',
            fn (array $schema): array => ['paths' => [], 'components' => ['responses' => ['default' => [
                'description' => 'Unexpected',
                'content' => ['application/json' => ['schema' => $schema]],
            ]]]],
            ['components', 'responses', 'default', 'content', 'application/json', 'schema'],
        ],
        'a header component named default' => [
            'a header component named default',
            fn (array $schema): array => ['paths' => [], 'components' => ['headers' => ['default' => ['schema' => $schema]]]],
            ['components', 'headers', 'default', 'schema'],
        ],
        'a response header named default' => [
            'a response header named default',
            fn (array $schema): array => ['paths' => ['/a' => ['get' => ['responses' => ['200' => [
                'description' => 'OK',
                'headers' => ['default' => ['schema' => $schema]],
            ]]]]]],
            ['paths', '/a', 'get', 'responses', '200', 'headers', 'default', 'schema'],
        ],
        'a response component named enum' => [
            'a response component named enum',
            fn (array $schema): array => ['paths' => [], 'components' => ['responses' => ['enum' => [
                'description' => 'Enumerated',
                'content' => ['application/json' => ['schema' => $schema]],
            ]]]],
            ['components', 'responses', 'enum', 'content', 'application/json', 'schema'],
        ],
        'a response component named example' => [
            'a response component named example',
            fn (array $schema): array => ['paths' => [], 'components' => ['responses' => ['example' => [
                'description' => 'Exemplary',
                'content' => ['application/json' => ['schema' => $schema]],
            ]]]],
            ['components', 'responses', 'example', 'content', 'application/json', 'schema'],
        ],
        'a parameter component named const' => [
            'a parameter component named const',
            fn (array $schema): array => ['paths' => [], 'components' => ['parameters' => ['const' => [
                'name' => 'pinned', 'in' => 'query', 'schema' => $schema,
            ]]]],
            ['components', 'parameters', 'const', 'schema'],
        ],
        // `components.examples` is a real bucket of Example Objects, not a schema's `examples` keyword —
        // so a `$ref` inside one is a Reference Object, and what it holds is still walked.
        'an example component named examples' => [
            'an example component named examples',
            fn (array $schema): array => ['paths' => [], 'components' => [
                'examples' => ['examples' => ['value' => ['kind' => 'widget']]],
                'headers' => ['h' => ['schema' => $schema]],
            ]],
            ['components', 'headers', 'h', 'schema'],
        ],
    ]);

    it('hands back the user data those names describe, wherever it really is', function (): void {
        $value = ['type' => ['string', 'null'], 'const' => 'x', 'get' => ['responses' => []], 'schema' => ['type' => ['integer', 'null']]];

        $result = (new OpenApi30DownlevelEmitter)->emitWithReport(UirDocument::fromArray([
            'uir' => '1.0.0',
            'openapi' => '3.2.0',
            'info' => ['title' => 'API', 'version' => '1.0.0'],
            'paths' => ['/a' => ['get' => ['responses' => ['200' => [
                'description' => 'OK',
                'content' => ['application/json' => [
                    'schema' => ['type' => 'object'],
                    'example' => $value,
                    'examples' => ['one' => ['value' => $value]],
                ]],
            ]]]]],
            'components' => ['examples' => ['two' => ['value' => $value]]],
        ]));

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);
        $media = $decoded['paths']['/a']['get']['responses']['200']['content']['application/json'];

        // Untouched at all three positions: a 2020-12 type array inside an example is what the API returns,
        // not a schema this emitter has any business rewriting. Member for member rather than byte for
        // byte, because the canonicalizer sorts an example's members after this emitter has had its say.
        expect($media['example'])->toEqual($value)
            ->and($media['examples']['one']['value'])->toEqual($value)
            ->and($decoded['components']['examples']['two']['value'])->toEqual($value)
            ->and(array_map(static fn ($d): string => $d->code, $result->report->diagnostics))->toBe([]);
    });

    it('reads a $ref in an examples map as a reference, and one in an example value as data', function (): void {
        $result = (new OpenApi30DownlevelEmitter)->emitWithReport(UirDocument::fromArray([
            'uir' => '1.0.0',
            'openapi' => '3.2.0',
            'info' => ['title' => 'API', 'version' => '1.0.0'],
            'paths' => ['/a' => ['get' => ['responses' => ['200' => [
                'description' => 'OK',
                'content' => ['application/json' => [
                    'examples' => [
                        'named' => ['$ref' => '#/components/examples/Shared', 'summary' => 'Reworded here'],
                        'inline' => ['value' => ['$ref' => '#/nothing/at/all', 'summary' => 'A payload that talks about refs']],
                    ],
                ],
                ],
            ]]]]],
            'components' => ['examples' => ['Shared' => ['value' => ['kind' => 'widget']]]],
        ]));

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);
        $examples = $decoded['paths']['/a']['get']['responses']['200']['content']['application/json']['examples'];

        expect($examples['named'])->toBe(['$ref' => '#/components/examples/Shared'])
            ->and($examples['inline']['value'])->toBe(['$ref' => '#/nothing/at/all', 'summary' => 'A payload that talks about refs'])
            ->and(array_map(static fn ($d): string => $d->code, $result->report->diagnostics))->toBe(['downlevel.ref-siblings']);
    });

    /**
     * A Link Object's `parameters` is `Map[string, Any | {expression}]` and its `requestBody` is `Any`:
     * values the application wrote, under keys the application chose. Read as fixed fields they reach the
     * schema handler, the path-item machinery and the reference handling, each of which rewrites what it
     * finds — and no meta-schema can see it, because `Any` stays valid whatever is done to it.
     *
     * The document lives here rather than in `downlevel.uir.json` because the published 3.1 and 3.2
     * meta-schemas type a Link's `parameters` as `Map[string, string]`, against their own prose — so a
     * fixture carrying an object-valued one would fail the oracle at those two versions while the 3.0
     * emission it produces is valid. The golden carries the half every version calls `Any`: `requestBody`.
     */
    it('hands back a Link Object\'s parameters and requestBody, however their members are spelled', function (): void {
        $link = [
            'operationId' => 'things.show',
            'description' => 'The thing this response created',
            'parameters' => [
                'path.id' => '$response.body#/id',
                // Spelled like a schema position, a path-item map, and a callbacks map.
                'schema' => ['type' => ['string', 'null'], 'const' => 'pinned'],
                'paths' => ['/audit' => ['get' => ['summary' => 'A body member, owed no responses']]],
                'callbacks' => ['onAudit' => ['/audit' => ['$ref' => '#/components/pathItems/Nothing']]],
            ],
            'requestBody' => ['$ref' => '#/nothing/at/all', 'summary' => 'A body member, not a reference'],
            'server' => ['url' => 'https://api.example.com'],
        ];

        $result = (new OpenApi30DownlevelEmitter)->emitWithReport(UirDocument::fromArray([
            'uir' => '1.0.0',
            'openapi' => '3.2.0',
            'info' => ['title' => 'API', 'version' => '1.0.0'],
            'paths' => ['/a' => ['get' => ['responses' => ['200' => [
                'description' => 'OK',
                'links' => ['self' => $link],
            ]]]]],
            'components' => ['links' => ['Self' => $link]],
        ]));

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);

        // Member for member rather than byte for byte: the canonicalizer sorts a Link's members after this
        // emitter has had its say, and what matters is that none of them changed.
        expect($decoded['paths']['/a']['get']['responses']['200']['links']['self'])->toEqual($link)
            ->and($decoded['components']['links']['Self'])->toEqual($link)
            ->and(array_map(static fn ($d): string => $d->code, $result->report->diagnostics))->toBe([]);
    });

    it('still reads a Link map member as a reference, and the Link\'s own fields as fields', function (): void {
        $result = (new OpenApi30DownlevelEmitter)->emitWithReport(UirDocument::fromArray([
            'uir' => '1.0.0',
            'openapi' => '3.2.0',
            'info' => ['title' => 'API', 'version' => '1.0.0'],
            'paths' => ['/a' => ['get' => ['responses' => ['200' => [
                'description' => 'OK',
                'links' => ['shared' => ['$ref' => '#/components/links/Audit', 'summary' => 'Reworded here']],
            ]]]]],
            'components' => ['links' => ['Audit' => [
                'operationId' => 'things.audit',
                // A Link's `server` is a Server Object, so the walk still descends into a Link.
                'server' => ['url' => 'https://api.example.com', 'variables' => ['region' => ['default' => 'eu']]],
                'requestBody' => ['$ref' => '#/still/data', 'summary' => 'Kept, being a body member'],
            ]]],
        ]));

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);

        // One reference in the links map, whose prose 3.0 ignores; one `$ref`-shaped body member, kept.
        expect($decoded['paths']['/a']['get']['responses']['200']['links']['shared'])->toBe(['$ref' => '#/components/links/Audit'])
            ->and($decoded['components']['links']['Audit']['requestBody'])->toEqual(['$ref' => '#/still/data', 'summary' => 'Kept, being a body member'])
            ->and($decoded['components']['links']['Audit']['server'])->toEqual(['url' => 'https://api.example.com', 'variables' => ['region' => ['default' => 'eu']]])
            ->and(array_map(static fn ($d): string => $d->code, $result->report->diagnostics))->toBe(['downlevel.ref-siblings']);
    });

    it('keeps a security requirement named like a component out of the scheme drop', function (): void {
        $result = (new OpenApi30DownlevelEmitter)->emitWithReport(UirDocument::fromArray([
            'uir' => '1.0.0',
            'openapi' => '3.2.0',
            'info' => ['title' => 'API', 'version' => '1.0.0'],
            'security' => [['cert' => [], 'apiKey' => []]],
            'paths' => [],
            'components' => [
                // A response component named `security`, whose members are not scheme names.
                'responses' => ['security' => ['description' => 'Not a requirement']],
                'securitySchemes' => [
                    'apiKey' => ['type' => 'apiKey', 'name' => 'X-Api-Key', 'in' => 'header'],
                    'cert' => ['type' => 'mutualTLS'],
                ],
            ],
        ]));

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);

        expect($decoded['security'])->toBe([['apiKey' => []]])
            ->and($decoded['components']['responses']['security'])->toBe(['description' => 'Not a requirement']);
    });

    /**
     * A Security Requirement Object's members are scheme names the application chose, and the walk used to
     * dispatch each of them as a fixed field. Nothing was corrupted, because a requirement's values are
     * lists of scope strings and every handler is a no-op on those — the guard held by accident of the
     * value type, which is what this pins now that it holds by position. Both halves of the branch are
     * here: an operation-level requirement, and `security: []` saying none is required.
     */
    it('reads a security requirement\'s members as scheme names, not as fixed fields', function (): void {
        $requirement = ['schema' => ['read'], 'paths' => [], 'callbacks' => ['write'], 'example' => [], 'links' => []];

        $result = (new OpenApi30DownlevelEmitter)->emitWithReport(UirDocument::fromArray([
            'uir' => '1.0.0',
            'openapi' => '3.2.0',
            'info' => ['title' => 'API', 'version' => '1.0.0'],
            'security' => [$requirement],
            'paths' => ['/a' => ['get' => [
                'security' => [],
                'responses' => ['200' => ['description' => 'OK']],
            ]]],
            'components' => ['securitySchemes' => array_map(
                static fn (): array => ['type' => 'apiKey', 'name' => 'X-Key', 'in' => 'header'],
                $requirement,
            )],
        ]));

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);

        // Member for member: the canonicalizer sorts a requirement's scheme names.
        expect($decoded['security'])->toEqual([$requirement])
            ->and($decoded['paths']['/a']['get']['security'])->toBe([])
            ->and(array_map(static fn ($d): string => $d->code, $result->report->diagnostics))->toBe([]);
    });
});
