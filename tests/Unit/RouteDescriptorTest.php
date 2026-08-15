<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\RouteDescriptor;

/**
 * RouteDescriptor identity + cache-signature soundness (design §10 / arch A1): the human signature
 * is method + URI (plus the host, when the route is bound to one), while the cache signature
 * additionally folds the route name, the resolved action target, normalised middleware and any scalar
 * cache inputs so a change to any of them busts the fragment cache even when the human signature is
 * unchanged.
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

it('names the host in the human signature, and names one verb of a multi-method route on request', function (): void {
    $bound = new RouteDescriptor(['GET', 'HEAD'], '/api/forms', domain: 'admin.example.com');
    $several = new RouteDescriptor(['PUT', 'PATCH'], '/api/forms/{form}');

    expect($bound->signature())->toBe('GET admin.example.com/api/forms')
        ->and($several->signature('patch'))->toBe('PATCH /api/forms/{form}')
        // A host-less route reads exactly as it always has.
        ->and((new RouteDescriptor(['GET'], '/api/forms'))->signature())->toBe('GET /api/forms');
});

it('folds the host into the cache signature, so two hosts never share a fragment', function (): void {
    $admin = new RouteDescriptor(['GET'], '/api/forms', action: 'A@i', domain: 'admin.example.com');
    $public = new RouteDescriptor(['GET'], '/api/forms', action: 'A@i', domain: 'www.example.com');
    $anyHost = new RouteDescriptor(['GET'], '/api/forms', action: 'A@i');

    expect($admin->cacheSignature())->not->toBe($public->cacheSignature())
        ->and($admin->cacheSignature())->not->toBe($anyHost->cacheSignature())
        // …and the two hosts are still one URI, which is the whole reason the key had to widen.
        ->and($admin->uri)->toBe($public->uri)
        ->and($admin->primaryMethod())->toBe($public->primaryMethod());
});

it('documents one operation per documentable method, dropping HEAD', function (): void {
    expect((new RouteDescriptor(['PUT', 'PATCH'], '/api/forms/{form}'))->documentableMethods())->toBe(['put', 'patch'])
        ->and((new RouteDescriptor(['GET', 'HEAD'], '/api/forms'))->documentableMethods())->toBe(['get'])
        ->and((new RouteDescriptor(['GET', 'POST'], '/api/x'))->documentableMethods())->toBe(['get', 'post'])
        ->and((new RouteDescriptor(['HEAD'], '/api/x'))->documentableMethods())->toBe(['head']);
});

it('leaves the fallback flag out of the cache signature', function (): void {
    // A catch-all is reported and omitted, so it never reaches a fragment and has nothing to key. The
    // flag being in the signature would only make an unrelated route's key depend on it.
    $ordinary = new RouteDescriptor(['GET'], '/api/{fallbackPlaceholder}', action: 'A@i');
    $catchAll = new RouteDescriptor(['GET'], '/api/{fallbackPlaceholder}', action: 'A@i', fallback: true);

    expect($catchAll->fallback)->toBeTrue()
        ->and($ordinary->fallback)->toBeFalse()
        ->and($catchAll->cacheSignature())->toBe($ordinary->cacheSignature());
});

it('folds a resolver\'s scalar cache inputs in, so a binding column busts the fragment', function (): void {
    // Laravel parses `:slug` out of the URI it reports, so the two descriptors below agree on
    // everything a key is otherwise made of.
    $key = new RouteDescriptor(['GET'], '/api/posts/{post}', action: 'A@show');
    $column = new RouteDescriptor(['GET'], '/api/posts/{post}', action: 'A@show', cacheInputs: ['binding:post=slug']);

    expect($key->signature())->toBe($column->signature())
        ->and($key->cacheSignature())->not->toBe($column->cacheSignature());
});
