<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Schema\ComponentNames;
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
    // A schema that names no identity IS its bytes, so two equal ones are one claim — the same-thing
    // case dedupe exists for, and the one place equal bytes are enough to merge on.
    $registry = new ComponentRegistry;

    $registry->registerSchema('Thing', ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]]);
    $name = $registry->registerSchema('Thing', ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]]);

    expect($name)->toBe('Thing')
        ->and($registry->schemas())->toHaveCount(1);
});

it('gives two identities two components even when their bodies are byte-equal', function (bool $reverse): void {
    // Dedupe exists to collapse ONE class registered twice. Collapsing two classes instead dropped the
    // newcomer's identity, so the surviving component carried whichever id registered first — route
    // order deciding what a component MEANS, under a `$ref` name that never moved to say so. The
    // published names have to be a function of the two claims and nothing else, either way round.
    $body = ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]];
    $ids = ['App\\Billing\\ReceiptData', 'App\\Support\\ReceiptData'];

    $registry = new ComponentRegistry;
    foreach ($reverse ? array_reverse($ids) : $ids as $id) {
        $registry->registerSchema('ReceiptData', $body, $id);
    }

    $renames = $registry->schemaRenames();
    $published = [];
    foreach ($registry->schemaIds() as $slot => $id) {
        $published[$id] = $renames[$slot] ?? $slot;
    }
    ksort($published);

    expect($registry->schemas())->toHaveCount(2)
        ->and($published)->toBe([
            'App\\Billing\\ReceiptData' => 'BillingReceiptData',
            'App\\Support\\ReceiptData' => 'SupportReceiptData',
        ])
        ->and($registry->nameCollisions())->toHaveCount(1);
})->with([false, true]);

it('never merges an identified schema into an anonymous one, or the reverse', function (bool $reverse): void {
    // The rule has to be symmetric. Merging one way only would make "one component or two" — and which
    // identity it carries — a question of which route the build met first.
    $body = ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]];

    $registry = new ComponentRegistry;
    foreach ($reverse ? [null, 'App\\Thing'] : ['App\\Thing', null] as $id) {
        $registry->registerSchema('Thing', $body, $id);
    }

    expect($registry->schemas())->toHaveCount(2)
        ->and($registry->schemaIds())->toHaveCount(1)
        ->and(array_values($registry->schemaIds()))->toBe(['App\\Thing']);
})->with([false, true]);

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

it('discriminates a shape that names no identity by the bytes it publishes, and still reports it', function (): void {
    // An inline shape has no namespace to derive a name from, but it does have content — which is
    // enough to keep it off the contested plain name without a suffix route order decided.
    $registry = new ComponentRegistry;

    $registry->registerSchema('Node', ['type' => 'object']);
    $registry->registerSchema('Node', ['type' => 'string'], 'App\\B\\Node');

    $renames = $registry->schemaRenames();

    expect($renames)->toHaveKeys(['Node', 'Node_2'])
        ->and($renames['Node_2'])->toBe('BNode')
        ->and($renames['Node'])->toStartWith('Node_')
        ->and($renames['Node'])->not->toBe('Node_2')
        ->and($registry->nameCollisions())->toHaveCount(1)
        ->and($registry->nameCollisions()[0]->message)
        ->toContain('an unidentified schema as "'.$renames['Node'].'"')
        ->toContain('App\\B\\Node as "BNode"');
});

it('publishes a schema under the name it asked for even when its slot kept a suffix', function (): void {
    // What a warm fragment cache hands over once the route that held the plain name is deleted: the
    // survivor re-registers under `Node`, so no `_2` names a class the document no longer holds.
    $registry = new ComponentRegistry;

    $registry->registerSchema('Node', ['type' => 'object'], 'App\\A\\Node');
    $registry->registerSchema('Node', ['type' => 'string'], 'App\\B\\Node');
    $survivor = new ComponentRegistry;
    $survivor->registerSchema($registry->schemaBases()['Node_2'], ['type' => 'string'], 'App\\B\\Node');

    expect($registry->schemaBases())->toBe(['Node' => 'Node', 'Node_2' => 'Node'])
        ->and($survivor->schemas())->toHaveKey('Node')
        ->and($survivor->schemaRenames())->toBe([]);
});

it('remembers the name a reserved schema asked for, so materialising it publishes the ask', function (): void {
    // A self-referential class takes its name before its body exists; the reservation has to carry the
    // ask across, or the body materialises into a slot with nothing to derive a published name from.
    $registry = new ComponentRegistry;

    $registry->registerSchema('Node', ['type' => 'object'], 'App\\A\\Node');
    $slot = $registry->reserveSchemaName('Node', 'App\\B\\Node');
    $registry->registerSchema('Node', ['type' => 'string'], 'App\\B\\Node');

    expect($slot)->toBe('Node_2')
        ->and($registry->schemaBases())->toBe(['Node' => 'Node', 'Node_2' => 'Node'])
        ->and($registry->schemaRenames())->toBe(['Node' => 'ANode', 'Node_2' => 'BNode']);
});

it('rolls the names a route asked for back with everything else it registered', function (): void {
    // The snapshot has to cover the asks too: a route that throws after registering must leave no
    // trace, and a base left behind would name a component the document never got.
    $registry = new ComponentRegistry;
    $snapshot = $registry->snapshot();

    $registry->registerSchema('Node', ['type' => 'object'], 'App\\A\\Node');
    $registry->reserveSchemaName('Other', 'App\\A\\Other');
    $registry->restore($snapshot);

    expect($registry->schemaBases())->toBe([])
        ->and($registry->schemas())->toBe([]);
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

it('answers the name rule and its wording as the one core owns', function (): void {
    // The extension author's view of a rule ComponentNames owns, and its only reader now that the
    // adapter's two `#[ErrorComponent]` producers share one report drawn from the owner directly. An alias
    // that drifted from its owner would send a refused author to a rule that is not the one applied.
    expect(ComponentRegistry::LEGAL_NAME_HELP)->toBe(ComponentNames::LEGAL_NAME_HELP)
        ->and((new ComponentRegistry)->isLegalName('NotFound'))->toBeTrue()
        ->and((new ComponentRegistry)->isLegalName('Not Found'))->toBeFalse();
});
