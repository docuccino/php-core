<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Inference;

use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Inference\TraceReport;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeScope;
use PhpParser\Node;

it('canonicalises dependency files: deduped and sorted', function (): void {
    $report = new TraceReport(['/b.php', '/a.php', '/b.php', '/c.php']);

    expect($report->dependencyFiles)->toBe(['/a.php', '/b.php', '/c.php'])
        ->and($report->toArray())->toBe(['dependencyFiles' => ['/a.php', '/b.php', '/c.php']]);
});

it('round-trips through toArray/fromArray', function (): void {
    $report = new TraceReport(['/z.php', '/a.php']);

    expect(TraceReport::fromArray($report->toArray())->dependencyFiles)
        ->toBe($report->dependencyFiles);
});

it('NullTypeEngine::trace returns an empty report and never invokes the visitor', function (): void {
    $visitor = new class implements TraceVisitor
    {
        public bool $entered = false;

        public function enterNode(Node $node, TypeScope $scope): bool
        {
            $this->entered = true;

            return true;
        }
    };

    $report = (new NullTypeEngine)->trace(new ActionRef('/app/Foo.php', 'App\\Foo', 'bar'), $visitor);

    expect($report)->toBeInstanceOf(TraceReport::class)
        ->and($report->dependencyFiles)->toBe([])
        ->and($visitor->entered)->toBeFalse();
});
