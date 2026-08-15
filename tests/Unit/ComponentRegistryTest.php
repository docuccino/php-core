<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;

it('dedupes a class by its schemaId across references', function (): void {
    $registry = new ComponentRegistry;

    $first = $registry->reference('FormData', ['type' => 'object'], 'App\\Data\\FormData');
    $second = $registry->reference('FormData', ['type' => 'object', 'title' => 'ignored'], 'App\\Data\\FormData');

    expect($first)->toBe(['$ref' => '#/components/schemas/FormData'])
        ->and($second)->toBe(['$ref' => '#/components/schemas/FormData'])
        ->and($registry->schemas())->toHaveCount(1)
        ->and($registry->diagnostics())->toBe([]);
});

it('dedupes structurally-equal anonymous schemas under one name', function (): void {
    $registry = new ComponentRegistry;

    $registry->registerSchema('Thing', ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]]);
    $name = $registry->registerSchema('Thing', ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]]);

    expect($name)->toBe('Thing')
        ->and($registry->schemas())->toHaveCount(1);
});

it('suffixes a genuine name collision, provisionally and silently', function (): void {
    // Registration order is route order, so the name it hands out is only ever provisional and it says
    // nothing about it. The published name — and the warning — come off the finished registry.
    $registry = new ComponentRegistry;

    $registry->registerSchema('Thing', ['type' => 'object']);
    $name = $registry->registerSchema('Thing', ['type' => 'string']);

    expect($name)->toBe('Thing_2')
        ->and($registry->schemas())->toHaveKeys(['Thing', 'Thing_2'])
        ->and($registry->diagnostics())->toBe([]);
});

it('publishes contested names off the FQCNs, on either registration path', function (string $path): void {
    // Both paths that suffix must end up under the same published names: registering a body, and
    // reserving a name up front for a self-referential class.
    $registry = new ComponentRegistry;

    if ($path === 'register') {
        $registry->registerSchema('Node', ['type' => 'object'], 'App\\A\\Node');
        $registry->registerSchema('Node', ['type' => 'string'], 'App\\B\\Node');
    } else {
        $registry->reserveSchemaName('Node', 'App\\A\\Node');
        $registry->reserveSchemaName('Node', 'App\\B\\Node');
        $registry->registerSchema('Node', ['type' => 'object'], 'App\\A\\Node');
        $registry->registerSchema('Node', ['type' => 'string'], 'App\\B\\Node');
    }

    expect($registry->schemaRenames())->toBe(['Node' => 'ANode', 'Node_2' => 'BNode']);
})->with(['register', 'reserve']);

it('names both classes and both published names in the collision warning', function (): void {
    // "Two schemas collided" is unactionable in an app with hundreds of DTOs — the short name in the
    // message is precisely the one that identifies neither claimant.
    $registry = new ComponentRegistry;
    $registry->registerSchema('Node', ['type' => 'object'], 'App\\A\\Node');
    $registry->registerSchema('Node', ['type' => 'string'], 'App\\B\\Node');

    $collisions = $registry->nameCollisions();

    expect($collisions)->toHaveCount(1)
        ->and($collisions[0]->severity)->toBe(Severity::Warning)
        ->and($collisions[0]->code)->toBe('components.name-collision')
        ->and($collisions[0]->message)
        ->toContain('"Node"')
        ->toContain('App\\A\\Node as "ANode"')
        ->toContain('App\\B\\Node as "BNode"')
        ->and($collisions[0]->help)->toContain('#[SchemaName]');
});

it('leaves a contest an unidentified shape is part of on its positional names, but still reports it', function (): void {
    // An inline shape has no namespace to derive a name from, so half-renaming the pair would be worse
    // than the suffix. The author still hears about it, and the message says what it can name.
    $registry = new ComponentRegistry;

    $registry->registerSchema('Node', ['type' => 'object']);
    $registry->registerSchema('Node', ['type' => 'string'], 'App\\B\\Node');

    expect($registry->schemaRenames())->toBe([])
        ->and($registry->nameCollisions())->toHaveCount(1)
        ->and($registry->nameCollisions()[0]->message)
        ->toContain('an unidentified schema as "Node"')
        ->toContain('App\\B\\Node as "Node_2"');
});

it('hands a snapshot-scoped slice of diagnostics to its caller and keeps none back', function (): void {
    // The seam that lets a route's fragment carry its own component diagnostics: what it takes is
    // exactly what was added since the snapshot, and the registry keeps none of it, so the assembler
    // draining the registry afterwards cannot report the same one twice.
    $registry = new ComponentRegistry;
    $registry->addDiagnostic(new Diagnostic(Severity::Info, 'demo.first', 'first'));

    $snapshot = $registry->snapshot();
    $registry->addDiagnostic(new Diagnostic(Severity::Info, 'demo.second', 'second'));

    $taken = $registry->takeDiagnosticsSince($snapshot);

    expect($taken)->toHaveCount(1)
        ->and($taken[0]->code)->toBe('demo.second')
        ->and($registry->diagnostics())->toHaveCount(1)
        ->and($registry->diagnostics()[0]->code)->toBe('demo.first');
});

it('takes nothing when a route registered no components at all', function (): void {
    // The overwhelmingly common case — the slice has to be empty, not the whole list.
    $registry = new ComponentRegistry;
    $registry->addDiagnostic(new Diagnostic(Severity::Info, 'demo.first', 'first'));

    expect($registry->takeDiagnosticsSince($registry->snapshot()))->toBe([])
        ->and($registry->diagnostics())->toHaveCount(1);
});

it('re-files a schema body only where the name still holds the identity given', function (): void {
    // What a warm cache hit uses to repoint bodies it filed under names that had moved. A name held by
    // another identity is left alone — the caller has no business rewriting someone else's component.
    $registry = new ComponentRegistry;
    $registry->registerSchema('Node', ['type' => 'object'], 'App\\A\\Node');

    $registry->replaceSchema('Node', ['type' => 'integer'], 'App\\A\\Node');
    $registry->replaceSchema('Node', ['type' => 'string'], 'App\\B\\Node');
    $registry->replaceSchema('Absent', ['type' => 'string'], 'App\\A\\Node');

    expect($registry->schemas())->toBe(['Node' => ['type' => 'integer']]);
});
