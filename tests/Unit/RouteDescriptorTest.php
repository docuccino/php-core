<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\RouteDescriptor;

/**
 * RouteDescriptor identity + cache-signature soundness (design §10 / arch A1): the human signature
 * is method + URI, while the cache signature additionally folds the route name, the resolved action
 * target, normalised middleware and any scalar cache inputs so a change to any of them busts the
 * fragment cache even when the human signature is unchanged.
 */
it('keeps the human signature to method + URI', function (): void {
    $descriptor = new RouteDescriptor(['GET', 'HEAD'], '/api/forms', action: 'App\\FormController@index', middleware: ['web', 'auth']);

    expect($descriptor->signature())->toBe('GET /api/forms');
});

it('folds the resolved action into the cache signature (re-pointing a route busts it)', function (): void {
    $before = new RouteDescriptor(['GET'], '/api/forms', action: 'App\\FormController@index');
    $after = new RouteDescriptor(['GET'], '/api/forms', action: 'App\\LegacyController@index');

    expect($before->signature())->toBe($after->signature())
        ->and($before->cacheSignature())->not->toBe($after->cacheSignature());
});

it('folds the route name into the cache signature (a rename changes the operationId)', function (): void {
    $before = new RouteDescriptor(['GET'], '/api/forms', name: 'forms.index', action: 'App\\FormController@index');
    $after = new RouteDescriptor(['GET'], '/api/forms', name: 'forms.list', action: 'App\\FormController@index');

    expect($before->signature())->toBe($after->signature())
        ->and($before->cacheSignature())->not->toBe($after->cacheSignature());
});

it('folds the normalised middleware into the cache signature (guard changes bust it)', function (): void {
    $open = new RouteDescriptor(['GET'], '/api/forms', action: 'A@i', middleware: ['web']);
    $guarded = new RouteDescriptor(['GET'], '/api/forms', action: 'A@i', middleware: ['web', 'auth:sanctum']);

    expect($open->cacheSignature())->not->toBe($guarded->cacheSignature());
});

it('normalises middleware whitespace but not order', function (): void {
    $spaced = new RouteDescriptor(['GET'], '/api/forms', action: 'A@i', middleware: [' web ', '', 'auth']);
    $clean = new RouteDescriptor(['GET'], '/api/forms', action: 'A@i', middleware: ['web', 'auth']);
    $reordered = new RouteDescriptor(['GET'], '/api/forms', action: 'A@i', middleware: ['auth', 'web']);

    expect($spaced->cacheSignature())->toBe($clean->cacheSignature())
        ->and($reordered->cacheSignature())->not->toBe($clean->cacheSignature());
});

it('folds extra scalar cache inputs into the signature', function (): void {
    $plain = new RouteDescriptor(['GET'], '/api/forms', action: 'A@i');
    $tagged = new RouteDescriptor(['GET'], '/api/forms', action: 'A@i', cacheInputs: ['locale:en']);

    expect($plain->cacheSignature())->not->toBe($tagged->cacheSignature());
});

it('documents one operation per documentable method, dropping HEAD', function (): void {
    expect((new RouteDescriptor(['PUT', 'PATCH'], '/api/forms/{form}'))->documentableMethods())->toBe(['put', 'patch'])
        ->and((new RouteDescriptor(['GET', 'HEAD'], '/api/forms'))->documentableMethods())->toBe(['get'])
        ->and((new RouteDescriptor(['GET', 'POST'], '/api/x'))->documentableMethods())->toBe(['get', 'post'])
        ->and((new RouteDescriptor(['HEAD'], '/api/x'))->documentableMethods())->toBe(['head']);
});
