<?php

declare(strict_types=1);

use Docuccino\Core\Contract\ContractChecker;
use Docuccino\Core\Contract\ContractIndex;
use Docuccino\Core\Contract\Examples\ExampleAudit;
use Docuccino\Core\Contract\Pointer;
use Docuccino\Core\Contract\Violation;

/**
 * A contract read at OpenAPI 3.0 holds a payload to what 3.0 says, not to what its spellings would mean as
 * JSON Schema 2020-12, which is the only dialect the validator reads. Three spellings differ: `nullable`
 * is a 3.0 keyword and no 2020-12 one, a `$ref`'s siblings "SHALL be ignored" in 3.0 and apply in 2020-12,
 * and a boolean `exclusiveMinimum` qualifies `minimum` in 3.0 where 2020-12 wants the bound itself.
 * Every pointer a failure names still addresses the document as written.
 */
it('holds a 3.0 contract to what 3.0 says where 2020-12 would say otherwise', function (array $schema, array $components, string $body): void {
    // The same schema under a 3.1 label is a different statement, so it is the label that decides.
    expect(checkVersionedResponse(versionedContract('3.0.3', $schema, $components), $body)->violations)->toBe([])
        ->and(checkVersionedResponse(versionedContract('3.1.0', $schema, $components), $body)->ok())->toBeFalse();
})->with([
    // 3.0.4: "A true value indicates that both null values and values of the type specified by type are allowed."
    'nullable beside a type admits null' => [['type' => 'string', 'nullable' => true], [], 'null'],
    // 3.0.4: a Reference Object's other properties "SHALL be ignored".
    'a $ref sibling constrains nothing' => [['$ref' => '#/components/schemas/Code', 'maxLength' => 2], ['Code' => ['type' => 'string']], '"abcdef"'],
    // The 3.0 spelling of a nullable reference, which 3.0 code generators read as nullable and the
    // document's own 3.1 and 3.2 exports publish as admitting null.
    'nullable beside no type admits null' => [['allOf' => [['$ref' => '#/components/schemas/Pet']], 'nullable' => true], ['Pet' => ['type' => 'object']], 'null'],
]);

it('still refuses what the 3.0 contract refuses', function (array $schema, array $components, string $body): void {
    expect(checkVersionedResponse(versionedContract('3.0.3', $schema, $components), $body)->ok())->toBeFalse();
})->with([
    'another type beside nullable' => [['type' => 'string', 'nullable' => true], [], '5'],
    // 3.0.4: "other Schema Object constraints retain their defined behavior".
    'null outside a nullable enum' => [['type' => 'string', 'enum' => ['a'], 'nullable' => true], [], 'null'],
    'the bound an exclusive minimum qualifies' => [['type' => 'integer', 'minimum' => 0, 'exclusiveMinimum' => true], [], '0'],
    'what the referenced schema says' => [['$ref' => '#/components/schemas/Code', 'maxLength' => 10], ['Code' => ['type' => 'string', 'maxLength' => 2]], '"abc"'],
    'what a nullable reference refers to' => [['allOf' => [['$ref' => '#/components/schemas/Pet']], 'nullable' => true], ['Pet' => ['type' => 'object']], '5'],
    'null where nothing says nullable' => [['type' => 'string', 'nullable' => false], [], 'null'],
]);

it('indexes and checks a 3.0 contract nested deeper than a restated `nullable` can wrap', function (): void {
    // Each type-less `nullable` here would be wrapped in an `anyOf`, two levels deeper apiece. Past the
    // bound a document can be written out at, the restatement once failed to encode and the contract read
    // as one with no operations, failing every route as undocumented.
    $deep = ['type' => 'string'];

    for ($level = 0; $level < 200; $level++) {
        $deep = ['nullable' => true, 'not' => $deep];
    }

    $document = versionedContract('3.0.3', ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean'], 'deep' => $deep], 'required' => ['ok']]);

    expect(checkVersionedResponse($document, '{"ok":true}')->violations)->toBe([])
        ->and(checkVersionedResponse($document, '{"ok":"yes"}')->ok())->toBeFalse();
});

it('names the document as written wherever a failure lands', function (array $schema, string $body, array $pointers): void {
    $document = versionedContract('3.0.3', $schema);
    $outcome = checkVersionedResponse($document, $body);
    $at = '/paths/~1a/get/responses/200/content/application~1json/schema';

    $reported = array_map(static fn (Violation $violation): string => $violation->schemaPointer, $outcome->violations);
    sort($reported);

    expect($reported)->toBe(array_map(static fn (string $pointer): string => $at.$pointer, $pointers));

    // Every one of them addresses a node the author wrote.
    foreach ($reported as $pointer) {
        $segments = array_map(Pointer::unescape(...), array_slice(explode('/', $pointer), 1));

        expect(Pointer::read($document, $segments))->not->toBeNull();
    }
})->with([
    'inside a nullable with no type' => [
        ['properties' => ['a' => ['type' => 'integer']], 'nullable' => true],
        '{"a":"x"}',
        ['/nullable', '/properties/a'],
    ],
    'inside the lone allOf member it wraps' => [
        ['allOf' => [['type' => 'object', 'properties' => ['a' => ['type' => 'integer']]]], 'nullable' => true],
        '{"a":"x"}',
        ['/allOf/0/properties/a', '/nullable'],
    ],
    'inside an anyOf of its own' => [
        ['anyOf' => [['type' => 'integer'], ['type' => 'string', 'minLength' => 3]], 'nullable' => true],
        '"ab"',
        ['/anyOf/0', '/anyOf/1', '/nullable'],
    ],
    'inside one wrapped in another' => [
        ['allOf' => [['properties' => ['a' => ['allOf' => [['type' => 'integer']], 'nullable' => true]]]], 'nullable' => true],
        '{"a":"x"}',
        ['/allOf/0/properties/a/allOf/0', '/allOf/0/properties/a/nullable', '/nullable'],
    ],
    'beside a type, where nothing moved' => [
        ['type' => 'object', 'properties' => ['n' => ['type' => 'integer', 'minimum' => 0, 'exclusiveMinimum' => true]], 'nullable' => true],
        '{"n":0}',
        ['/properties/n'],
    ],
]);

it('reads who wrote a failing node off the document as written', function (): void {
    $record = ['producer' => 'integration:eloquent', 'layer' => 'integration', 'fields' => ['type'], 'source' => ['file' => 'app/Models/Pet.php', 'line' => 12]];
    $document = versionedContract('3.0.3', [
        'allOf' => [[
            'x-docuccino' => ['id' => 'schema:pet', 'provenance' => [$record]],
            'properties' => ['age' => ['type' => 'integer']],
        ]],
        'nullable' => true,
    ]);

    $index = ContractIndex::fromJson((string) json_encode($document));
    $violations = (new ContractChecker($index))->check(contractExchange('GET', '/a', responseBody: '{"age":"old"}'))->response->violations ?? [];
    $at = '/paths/~1a/get/responses/200/content/application~1json/schema';

    expect($violations[0]->schemaPointer)->toBe($at.'/allOf/0/properties/age')
        ->and($violations[0]->provenance->pointer)->toBe($at.'/allOf/0')
        ->and($violations[0]->provenance->lines())->toBe(['integration:eloquent (integration) — app/Models/Pet.php:12'])
        ->and($index->identities()['schema:pet'])->toBe(['paths', '/a', 'get', 'responses', '200', 'content', 'application/json', 'schema', 'allOf', '0'])
        ->and($index->provenanceOf('schema:pet')->pointer)->toBe($at.'/allOf/0');
});

it('gives each exchange the verdict the document’s own 3.2 export gives it', function (string $method, string $path, int $status, string $responseBody, array $query, string $requestBody, bool $ok): void {
    $source = (string) file_get_contents(__DIR__.'/../../Fixtures/openapi30-source.json');
    $export = (string) file_get_contents(__DIR__.'/../../Fixtures/golden/openapi30-source.openapi32.json');
    $exchange = contractExchange($method, $path, status: $status, responseBody: $responseBody, query: $query, requestBody: $requestBody);

    $checked = (new ContractChecker(ContractIndex::fromJson($source)))->check($exchange);

    expect($checked->ok())->toBe($ok)
        ->and((new ContractChecker(ContractIndex::fromJson($export)))->check($exchange)->ok())->toBe($ok);

    // And each failure on the 3.0 side names a node the source has.
    $written = json_decode($source, true);
    foreach ($checked->failures() as $half) {
        foreach ($half->violations as $violation) {
            $segments = array_map(Pointer::unescape(...), array_slice(explode('/', $violation->schemaPointer), 1));

            expect(Pointer::read($written, $segments))->not->toBeNull();
        }
    }
})->with([
    'a nullable reference sent null' => ['POST', '/pets', 201, '{"id":1,"name":"Rex","owner":null,"species":null,"adoptedAt":null}', [], '{"name":"Rex","owner":null}', true],
    'a nullable reference sent the wrong thing' => ['POST', '/pets', 201, '{"id":1,"name":"Rex","owner":5}', [], '{"name":"Rex"}', false],
    'a nullable union sent neither' => ['POST', '/pets', 201, '{"id":1,"name":"Rex","species":{"kind":"fish"}}', [], '{"name":"Rex"}', false],
    'null outside a nullable enum' => ['POST', '/pets', 201, '{"id":1,"name":"Rex","status":null}', [], '{"name":"Rex"}', false],
    'a member the enum names' => ['POST', '/pets', 201, '{"id":1,"name":"Rex","status":"sold"}', [], '{"name":"Rex"}', true],
    'an exclusive bound met' => ['POST', '/pets', 201, '{"id":1,"name":"Rex","species":{"kind":"dog","weight":0}}', [], '{"name":"Rex"}', false],
    'an exclusive bound cleared' => ['POST', '/pets', 201, '{"id":1,"name":"Rex","species":{"kind":"dog","weight":0.5}}', [], '{"name":"Rex"}', true],
    'a nullable query value in range' => ['GET', '/pets', 200, '[]', ['limit' => '5'], '', true],
    'a nullable query value on its exclusive bound' => ['GET', '/pets', 200, '[]', ['limit' => '0'], '', false],
]);

it('audits a 3.0 contract’s examples by what 3.0 says, naming each where it was written', function (): void {
    $document = versionedContract('3.0.3', ['$ref' => '#/components/schemas/S'], [
        'S' => [
            'nullable' => true,
            'properties' => [
                'nick' => ['type' => 'string', 'nullable' => true, 'example' => null],
                'meta' => ['type' => 'object', 'example' => new stdClass],
                'age' => ['type' => 'integer', 'example' => 'old'],
            ],
        ],
    ]);

    $report = (new ExampleAudit(ContractIndex::fromJson((string) json_encode($document))))->run();

    // The empty object stays one: an artifact's `{}` is not the `[]` a PHP array would make of it.
    expect($report->checked)->toBe(3)
        ->and($report->uncheckable)->toBe([])
        ->and(array_map(static fn ($finding): string => $finding->pointer, $report->findings))
        ->toBe(['/components/schemas/S/properties/age/example']);
});
