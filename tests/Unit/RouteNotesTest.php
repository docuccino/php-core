<?php

declare(strict_types=1);

use Docuccino\Core\Document\Operation;
use Docuccino\Core\Extensions\Context\RouteNotes;
use Docuccino\Core\Pipeline\OperationFragment;

/**
 * The note bag a route writes a document-level finding into, and its trip through the operation fragment.
 * Both halves matter: a note is what makes a warm build report what a cold one reports, and it can only do
 * that if it survives JSON and comes back in the same order.
 */
it('dedupes a value recorded twice under one key', function (): void {
    $notes = new RouteNotes;
    $notes->record('deferral', 'App\\Renderer::__invoke', 'NotFound');
    $notes->record('deferral', 'App\\Renderer::__invoke', 'NotFound');

    expect($notes->all())->toBe(['deferral' => ['App\\Renderer::__invoke' => ['NotFound']]]);
});

it('sorts channels, keys and values, so what a route contributes is a function of what it found', function (): void {
    $notes = new RouteNotes;
    $notes->record('zeta', 'b', 'two');
    $notes->record('alpha', 'z', 'y');
    $notes->record('alpha', 'a', 'second');
    $notes->record('alpha', 'a', 'first');
    $notes->record('zeta', 'a', 'one');

    expect($notes->all())->toBe([
        'alpha' => ['a' => ['first', 'second'], 'z' => ['y']],
        'zeta' => ['a' => ['one'], 'b' => ['two']],
    ]);
});

it('records nothing when nothing was recorded', function (): void {
    expect((new RouteNotes)->all())->toBe([]);
});

it('carries notes through the fragment round trip', function (): void {
    $fragment = new OperationFragment(
        path: '/api/forms',
        method: 'get',
        operation: Operation::fromArray([]),
        routeSignature: 'GET /api/forms',
        notes: ['deferral' => ['App\\Renderer::__invoke' => ['NotFound', 'TooMany']]],
    );

    expect(OperationFragment::fromArray($fragment->toArray())->notes)->toBe($fragment->notes);
});

it('keeps a fragment’s notes when its components are repointed', function (): void {
    // A warm hit renames the components a route added since took the name of; the notes are about the
    // route's own findings and have nothing to do with that, so they must come through untouched.
    $fragment = new OperationFragment(
        path: '/api/forms',
        method: 'get',
        operation: Operation::fromArray(['responses' => ['404' => ['$ref' => '#/components/responses/NotFound']]]),
        routeSignature: 'GET /api/forms',
        componentResponses: ['NotFound' => ['description' => 'Gone']],
        notes: ['deferral' => ['App\\Renderer::__invoke' => ['NotFound']]],
    );

    expect($fragment->withRenamedComponents([], ['NotFound' => 'NotFound_2'])->notes)->toBe($fragment->notes);
});

it('drops a malformed note rather than hydrating a shape nothing can replay', function (): void {
    // The cache file is JSON on disk and can be anything; a note whose values aren't strings is dropped
    // the way every other member of the fragment is, so a corrupt entry degrades to fewer notes and never
    // to a type error mid-build.
    $notes = OperationFragment::fromArray([
        'notes' => [
            'deferral' => ['App\\Renderer::__invoke' => ['NotFound', ['nested'], 7]],
            'broken' => 'not a map',
        ],
    ])->notes;

    expect($notes)->toBe(['deferral' => ['App\\Renderer::__invoke' => ['NotFound']]]);
});
