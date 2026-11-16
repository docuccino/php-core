<?php

declare(strict_types=1);

use Docuccino\Core\Document\Operation;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Overlay\OverlayDocument;
use Docuccino\Core\Pipeline\Assembler;
use Docuccino\Core\Pipeline\AssemblyResult;
use Docuccino\Core\Pipeline\OperationFragment;

/*
 * A warm build re-registers only the components an operation reaches, so a body a producer registered and
 * then did not use — overridden, restated inline, dropped — would be published by a cold build alone, as a
 * type nothing in the document refers to. The build publishes what its operations reach and nothing else,
 * which is what the warm build already did. A component reached only through another one, a discriminator
 * mapping, a callback or the trail of an overridden value is reached all the same.
 */

/**
 * @param  callable(ComponentRegistry): void  $register
 * @param  array<string, mixed>  $operation
 * @param  list<OverlayDocument>  $overlays
 */
function assembleWithRegistry(callable $register, array $operation, array $overlays = []): AssemblyResult
{
    $registry = new ComponentRegistry;
    $register($registry);

    return (new Assembler('docuccino'))->assemble(
        [new OperationFragment('/api/things', 'post', Operation::fromArray($operation), 'POST /api/things')],
        new DocumentConfig('default', ['title' => 'T', 'version' => '1.0.0']),
        'doc:default',
        $registry,
        $overlays,
        [],
        '1.0.0',
    );
}

$json = static fn (string $pointer): array => ['content' => ['application/json' => ['schema' => ['$ref' => $pointer]]]];

it('publishes what the operations reach, however indirectly, and nothing they do not', function () use ($json): void {
    $document = assembleWithRegistry(static function (ComponentRegistry $registry): void {
        $registry->registerSchema('Thing', ['type' => 'object', 'properties' => ['part' => ['$ref' => '#/components/schemas/Part']]], 'App\\Thing');
        $registry->registerSchema('Part', ['type' => 'object'], 'App\\Part');
        $registry->registerSchema('Pet', ['oneOf' => [['$ref' => '#/components/schemas/Cat']], 'discriminator' => ['propertyName' => 'kind', 'mapping' => ['dog' => '#/components/schemas/Dog']]], 'App\\Pet');
        $registry->registerSchema('Cat', ['type' => 'object'], 'App\\Cat');
        $registry->registerSchema('Dog', ['type' => 'object'], 'App\\Dog');
        $registry->registerSchema('Hooked', ['type' => 'object'], 'App\\Hooked');
        $registry->registerSchema('Inferred', ['type' => 'object'], 'App\\Inferred');
        $registry->registerSchema('Restated', ['type' => 'object'], 'App\\Restated');
        $registry->registerResponse('Error', ['description' => 'Error', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Pet']]]]);
        $registry->registerResponse('Dropped', ['description' => 'Dropped']);
    }, [
        'requestBody' => $json('#/components/schemas/Thing'),
        'responses' => [
            '200' => ['description' => 'OK', 'content' => ['application/json' => ['schema' => [
                '$ref' => '#/components/schemas/Thing',
                'x-docuccino' => ['provenance' => [['producer' => 'attribute', 'layer' => 'attribute', 'fields' => ['$ref'], 'overrode' => [['field' => '$ref', 'value' => '#/components/schemas/Inferred', 'producer' => 'inference']]]]],
            ]]]],
            '422' => ['$ref' => '#/components/responses/Error'],
        ],
        'callbacks' => ['done' => ['{$request.body#/url}' => ['post' => [
            'requestBody' => $json('#/components/schemas/Hooked'),
            'responses' => ['200' => ['description' => 'OK']],
        ]]]],
    ])->document;

    // `Restated` and `Dropped` were registered and never used; a warm build would never have them.
    expect(array_keys($document['components']['schemas']))->toBe(['Cat', 'Dog', 'Hooked', 'Inferred', 'Part', 'Pet', 'Thing'])
        ->and(array_keys($document['components']['responses']))->toBe(['Error'])
        ->and(unreachableComponents($document))->toBe([]);
});

it('settles names over what is published, so a body nothing uses takes no name from one that is used', function () use ($json): void {
    $result = assembleWithRegistry(static function (ComponentRegistry $registry): void {
        $registry->registerSchema('Node', ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]], 'App\\A\\Node');
        $registry->registerSchema('Node', ['type' => 'object', 'properties' => ['b' => ['type' => 'string']]], 'App\\B\\Node');
    }, ['responses' => ['200' => ['description' => 'OK'] + $json('#/components/schemas/Node_2')]]);

    // Unpruned, the two contest `Node`, both are published as `ANode`/`BNode`, and a collision is reported
    // over a type no client can receive — a different name and a different report from a warm build's.
    expect(array_keys($result->document['components']['schemas']))->toBe(['Node'])
        ->and($result->document['components']['schemas']['Node']['properties'])->toBe(['b' => ['type' => 'string']])
        ->and($result->document['paths']['/api/things']['post']['responses']['200']['content']['application/json']['schema'])->toBe(['$ref' => '#/components/schemas/Node'])
        ->and(array_filter($result->diagnostics(), static fn ($d): bool => $d->code === 'components.name-collision'))->toBe([]);
});

it('keeps what an overlay adds, and the security schemes a requirement names rather than points at', function (): void {
    $document = assembleWithRegistry(static function (ComponentRegistry $registry): void {
        $registry->registerSecurityScheme('bearer', ['type' => 'http', 'scheme' => 'bearer']);
    }, ['responses' => ['204' => ['description' => 'Done']]], [OverlayDocument::fromArray(['overlay' => '1.0.0', 'actions' => [
        ['target' => '$.components', 'update' => ['schemas' => ['Shared' => ['type' => 'object']]]],
    ]])])->document;

    expect(array_keys($document['components']['schemas']))->toBe(['Shared'])
        ->and(array_keys($document['components']['securitySchemes']))->toBe(['bearer']);
});
