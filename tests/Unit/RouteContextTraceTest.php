<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeScope;
use Docuccino\Core\Tests\Support\StubTypeEngine;
use PhpParser\Node;

/**
 * The context's two trace entry points. Both record the walk's dependency files, so an extension
 * seeding a root of its own never has to remember to — a walk recorded nowhere leaves a stale
 * fragment warm.
 */
$action = new ActionRef('app/Http/GadgetController.php', 'App\\Http\\GadgetController', 'index');
$constructor = new ActionRef('app/Queries/GadgetQuery.php', 'App\\Queries\\GadgetQuery', '__construct');

it('records a seeded root\'s dependency files, so a warm fragment cannot go stale', function () use ($action, $constructor): void {
    $context = new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/gadgets'),
        actionRef: $action,
        attributes: new AttributeSet,
        engine: new StubTypeEngine,
        document: new DocumentConfig('default', []),
    );

    $context->traceFrom($constructor, new class implements TraceVisitor
    {
        public function enterNode(Node $node, TypeScope $scope): bool
        {
            return false;
        }
    });

    // The bag, not only dependencyFiles(): the root's file has to arrive through the recording, never
    // because the action analysis happened to mention it anyway.
    expect($context->dependencies()->files())->toBe(['app/Queries/GadgetQuery.php'])
        ->and($context->dependencyFiles())->toBe(['app/Http/GadgetController.php', 'app/Queries/GadgetQuery.php']);
});

it('walks the root it is handed, and returns that walk\'s report', function () use ($action, $constructor): void {
    $walked = [];
    $engine = new StubTypeEngine(traces: [
        $action->symbol() => function (TraceVisitor $visitor) use (&$walked): void {
            $walked[] = 'action';
        },
        $constructor->symbol() => function (TraceVisitor $visitor) use (&$walked): void {
            $walked[] = 'constructor';
        },
    ]);

    $context = new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/gadgets'),
        actionRef: $action,
        attributes: new AttributeSet,
        engine: $engine,
        document: new DocumentConfig('default', []),
    );

    $report = $context->traceFrom($constructor, new class implements TraceVisitor
    {
        public function enterNode(Node $node, TypeScope $scope): bool
        {
            return false;
        }
    });

    expect($walked)->toBe(['constructor'])
        ->and($report->dependencyFiles)->toBe(['app/Queries/GadgetQuery.php']);
});

it('still traces the action itself, and records that walk too', function () use ($action): void {
    $walked = [];
    $engine = new StubTypeEngine(traces: [
        $action->symbol() => function (TraceVisitor $visitor) use (&$walked): void {
            $walked[] = 'action';
        },
    ]);

    $context = new RouteContext(
        route: new RouteDescriptor(['GET'], 'api/gadgets'),
        actionRef: $action,
        attributes: new AttributeSet,
        engine: $engine,
        document: new DocumentConfig('default', []),
    );

    $report = $context->trace(new class implements TraceVisitor
    {
        public function enterNode(Node $node, TypeScope $scope): bool
        {
            return false;
        }
    });

    expect($walked)->toBe(['action'])
        ->and($report->dependencyFiles)->toBe(['app/Http/GadgetController.php'])
        ->and($context->dependencies()->files())->toBe(['app/Http/GadgetController.php']);
});
