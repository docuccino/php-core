<?php

declare(strict_types=1);

use Docuccino\Core\Document\Operation;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Pipeline\Assembler;
use Docuccino\Core\Pipeline\OperationFragment;

/*
 * Two classes contesting one component name leave it, and assembly moves every reference to the name
 * each of them is published under. An example is not a reference: an API that serves schema documents
 * sends the pointer its payload carries, and the example has to go on saying what the server sends —
 * not the name of whichever class happened to register first under the one it spells.
 */
it('moves the references to a contested component, and leaves the example that spells it as it was', function (): void {
    $registry = new ComponentRegistry;
    $registry->registerSchema('Node', ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]], 'App\\A\\Node');
    $registry->registerSchema('Node', ['type' => 'object', 'properties' => ['b' => ['type' => 'string']]], 'App\\B\\Node');

    $sent = ['name' => 'node', 'schema' => ['$ref' => '#/components/schemas/Node']];

    $operation = Operation::fromArray(['responses' => ['200' => ['description' => 'OK', 'content' => ['application/json' => [
        'schema' => ['type' => 'object', 'properties' => [
            'node' => ['$ref' => '#/components/schemas/Node'],
            'schema' => ['type' => 'object', 'example' => $sent['schema']],
        ]],
        'example' => $sent,
    ]]]]]);

    $document = (new Assembler('docuccino'))->assemble(
        [new OperationFragment('/api/schemas', 'get', $operation, 'GET /api/schemas')],
        new DocumentConfig('default', ['title' => 'T', 'version' => '1.0.0']),
        'doc:default',
        $registry,
        [],
        [],
        '1.0.0',
    )->document;

    $media = $document['paths']['/api/schemas']['get']['responses']['200']['content']['application/json'];

    expect(array_keys($document['components']['schemas']))->toBe(['ANode', 'BNode'])
        ->and($media['schema']['properties']['node'])->toBe(['$ref' => '#/components/schemas/ANode'])
        ->and($media['example'])->toBe($sent)
        ->and($media['schema']['properties']['schema']['example'])->toBe($sent['schema']);
});
