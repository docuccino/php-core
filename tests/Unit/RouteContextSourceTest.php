<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Provenance\RootRelativeSourcePathResolver;
use Docuccino\Core\Tests\Support\StubTypeEngine;

/**
 * The provenance source an extension attaches to a contribution or a diagnostic. Both halves are
 * published — `file` in `x-docuccino.provenance`, and the `symbol` beside it — so neither may name the
 * build machine, which is what makes `ActionRef::symbol()` (an identity key that falls back to the
 * FILE) the wrong thing to hand over as it stands.
 */
$context = static fn (ActionRef $action, bool $resolver = true): RouteContext => new RouteContext(
    route: new RouteDescriptor(['GET'], 'api/gadgets'),
    actionRef: $action,
    attributes: new AttributeSet,
    engine: new StubTypeEngine,
    document: new DocumentConfig('default', []),
    pathResolver: $resolver ? new RootRelativeSourcePathResolver('/home/alice/checkout') : null,
);

it('relativises the symbol beside the file, so a closure route names no machine', function () use ($context): void {
    // A closure has no class, so what identifies it is its FILE — absolutely. The `file` half has always
    // been relativised; unrelativised, the `symbol` beside it published `/home/alice/checkout/…`, which
    // is the one thing byte-identical output forbids, in the hardest place to notice it.
    $source = $context(new ActionRef('/home/alice/checkout/routes/api.php', null, '{closure}', 12))->actionSource();

    expect($source?->toArray())->toBe(['file' => 'routes/api.php', 'line' => 12, 'symbol' => 'routes/api.php::{closure}']);
});

it('leaves an ordinary controller action exactly as it was', function () use ($context): void {
    $source = $context(new ActionRef('/home/alice/checkout/app/Http/GadgetController.php', 'App\\Http\\GadgetController', 'index', 30))->actionSource();

    expect($source?->toArray())->toBe([
        'file' => 'app/Http/GadgetController.php',
        'line' => 30,
        'symbol' => 'App\\Http\\GadgetController::index',
    ]);
});

it('names an anonymous action class by where it stands', function () use ($context): void {
    // `::class` on an anonymous class carries a NUL byte, the absolute file and a counter of the
    // anonymous classes the PROCESS declared before it — order-dependent as well as machine-dependent.
    $source = $context(new ActionRef(
        '/home/alice/checkout/app/Http/Inline.php',
        "class@anonymous\0/home/alice/checkout/app/Http/Inline.php:9\$1f",
        'index',
    ))->actionSource();

    expect($source?->toArray())->toBe([
        'file' => 'app/Http/Inline.php',
        'symbol' => 'class@anonymous declared in app/Http/Inline.php:9::index',
    ]);
});

it('has no source at all where there is no resolver or no file', function () use ($context): void {
    expect($context(new ActionRef('', null, '{closure}'))->actionSource())->toBeNull()
        ->and($context(new ActionRef('/home/alice/checkout/routes/api.php', null, '{closure}'), false)->actionSource())->toBeNull();
});

it('hands a message the same label the source publishes', function () use ($context): void {
    // A diagnostic that prints the action in its SENTENCE had only `ActionRef::symbol()` to reach for,
    // and that is the identity key the source half already refuses. One answer for both, or the two
    // spellings of one action drift and only one of them is publishable.
    $closure = $context(new ActionRef('/home/alice/checkout/routes/api.php', null, '{closure}', 12));

    expect($closure->actionLabel())->toBe('routes/api.php::{closure}')
        ->and($closure->actionLabel())->toBe($closure->actionSource()?->symbol);
});

it('still names an action rather than a machine where there is no resolver', function () use ($context): void {
    // No resolver means no SOURCE — better none than a churny path. A message has no such option: it
    // either names the action or names nothing, so the label falls back to the composer-root walk.
    $label = $context(new ActionRef('/home/alice/checkout/routes/api.php', null, '{closure}'), false)->actionLabel();

    expect($label)->toEndWith('api.php::{closure}')
        ->and($label)->not->toContain('/home/alice/checkout/routes/');
});
