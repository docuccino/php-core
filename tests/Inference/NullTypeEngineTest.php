<?php

declare(strict_types=1);

use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\CallableRef;
use Docuccino\Core\Inference\ClassRef;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\ReportsBootFailure;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeScope;
use PhpParser\Node;

/**
 * The totalising fallback: it answers everything with nothing, and — when it is standing in for an
 * engine that failed to boot — it carries the error that put it there, which is the only way a host
 * that never imported the engine can tell a degraded build from a configured one.
 */
it('answers every question with nothing, and reports no boot failure by default', function (): void {
    $engine = new NullTypeEngine;
    $action = new ActionRef('/app/Controller.php', 'App\\Controller', 'index');
    $visitor = new class implements TraceVisitor
    {
        public function enterNode(Node $node, TypeScope $scope): bool
        {
            return false;
        }
    };

    expect($engine)->toBeInstanceOf(ReportsBootFailure::class)
        ->and($engine->bootFailure())->toBeNull()
        ->and($engine->analyzeAction($action)->returns)->toBe([])
        ->and($engine->analyzeAction($action)->dependencyFiles)->toBe(['/app/Controller.php'])
        ->and($engine->analyzeCallable(new CallableRef('/app/Handler.php', 'App\\Handler', 'render'))->dependencyFiles)
        ->toBe(['/app/Handler.php'])
        ->and($engine->classMetadata(new ClassRef('App\\Data'))->fqcn)->toBe('App\\Data')
        ->and($engine->trace($action, $visitor)->dependencyFiles)->toBe([]);
});

it('carries the boot error it stood in for', function (): void {
    // Verbatim: the host relativises the machine paths in it, and cannot do that to words we reworded.
    $engine = new NullTypeEngine('Failed to boot the PHPStan/Larastan container: nope');

    expect($engine->bootFailure())->toBe('Failed to boot the PHPStan/Larastan container: nope');
});
