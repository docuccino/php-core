<?php

declare(strict_types=1);

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Pipeline\Assembler;
use Docuccino\Core\Pipeline\OperationFragment;

/**
 * Where a component sits in its bucket is a function of its NAME. Registration order is not one: a warm
 * cache hit re-registers off the fragments it restored rather than off the routes that built them, and
 * an added route registers wherever it happens to fall. The canonicalizer sorts these buckets on the way
 * out, so a document read AS EMITTED never showed it — but overlays, transformers, lints and the differ
 * read the assembled document first.
 */

/**
 * Assemble one document over a registry filled in `$order`, and hand back its component buckets.
 *
 * @param  list<string>  $order
 * @return array<string, list<string>>
 */
function assembledComponentOrder(array $order): array
{
    $registry = new ComponentRegistry;

    foreach ($order as $name) {
        $registry->registerSchema($name, ['type' => 'object', 'title' => $name], 'App\\'.$name);
        $registry->registerResponse($name, ['description' => $name]);
        $registry->registerSecurityScheme($name, ['type' => 'http', 'scheme' => 'bearer', 'description' => $name]);
    }

    $result = (new Assembler('docuccino'))->assemble(
        [new OperationFragment('/api/reports', 'get', (new OperationDraft)->freeze(), 'GET /api/reports')],
        new DocumentConfig('default', ['title' => 'T', 'version' => '1.0.0']),
        'doc:default',
        $registry,
        [],
        [],
        '1.0.0',
    );

    $components = $result->document['components'];

    return array_map(array_keys(...), $components);
}

it('publishes every component bucket in name order, whatever order the build registered them in', function (): void {
    $forwards = assembledComponentOrder(['Widget', 'Author', 'Article', 'Gadget']);

    expect($forwards)->toBe([
        'schemas' => ['Article', 'Author', 'Gadget', 'Widget'],
        'responses' => ['Article', 'Author', 'Gadget', 'Widget'],
        'securitySchemes' => ['Article', 'Author', 'Gadget', 'Widget'],
    ])->and(assembledComponentOrder(['Article', 'Gadget', 'Widget', 'Author']))->toBe($forwards);
});
