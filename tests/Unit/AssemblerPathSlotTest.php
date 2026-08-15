<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Pipeline\Assembler;
use Docuccino\Core\Pipeline\OperationFragment;

/**
 * A path and a method address exactly one operation in OpenAPI, so two fragments claiming one slot is
 * a case the document cannot represent. The rule is that the first claimant keeps the slot and the
 * loser is REPORTED — the alternative, an overwrite, deletes a documented endpoint with nothing said.
 *
 * Duplicate operation IDENTITY is the separate, orthogonal report: two fragments can share an identity
 * from different slots (`/users/{user}` and `/users/{id}` normalise to one template), which loses
 * nothing from the document but breaks how a semantic diff pairs them.
 */
beforeEach(function (): void {
    $this->fragment = static function (string $path, string $method, string $signature, string $id): OperationFragment {
        $draft = new OperationDraft;
        $draft->setSummary($signature, Contribution::fallback());
        $draft->assignId($id);

        return new OperationFragment($path, $method, $draft->freeze(), $signature);
    };

    $this->assemble = static function (array $fragments): array {
        $result = (new Assembler('docuccino'))->assemble(
            $fragments,
            new DocumentConfig('default', ['title' => 'T', 'version' => '1.0.0']),
            'doc:default',
            new ComponentRegistry,
            [],
            [],
            '1.0.0',
        );

        return [$result->document, $result->diagnostics];
    };
});

it('keeps the first claimant of a path and method, and reports the operation it could not emit', function (): void {
    [$document, $diagnostics] = ($this->assemble)([
        ($this->fragment)('/api/reports', 'get', 'GET a.example.com/api/reports', 'op:v1:aaaaaaaaaaaaaaaa'),
        ($this->fragment)('/api/reports', 'get', 'GET b.example.com/api/reports', 'op:v1:bbbbbbbbbbbbbbbb'),
    ]);

    $collisions = array_values(array_filter($diagnostics, fn (Diagnostic $d): bool => $d->code === 'paths.operation-collision'));

    expect($document['paths']['/api/reports'])->toHaveKey('get')
        ->and($document['paths']['/api/reports']['get']['summary'])->toBe('GET a.example.com/api/reports')
        ->and($collisions)->toHaveCount(1)
        // Error, not a warning: an endpoint the app serves is missing from the document.
        ->and($collisions[0]->severity->value)->toBe('error')
        ->and($collisions[0]->routeSignature)->toBe('GET b.example.com/api/reports')
        ->and($collisions[0]->message)->toContain('GET /api/reports')
        ->and($collisions[0]->message)->toContain('GET a.example.com/api/reports')
        ->and($collisions[0]->help)->toContain('its own document');
});

it('reports a shared identity and a taken slot as the two different problems they are', function (string $secondPath, string $secondId, array $expected): void {
    [, $diagnostics] = ($this->assemble)([
        ($this->fragment)('/api/users/{user}', 'get', 'GET /api/users/{user}', 'op:v1:aaaaaaaaaaaaaaaa'),
        ($this->fragment)($secondPath, 'get', 'GET '.$secondPath, $secondId),
    ]);

    $codes = array_values(array_map(
        fn (Diagnostic $d): string => $d->code,
        array_filter($diagnostics, fn (Diagnostic $d): bool => str_starts_with($d->code, 'identity.') || str_starts_with($d->code, 'paths.')),
    ));

    expect($codes)->toBe($expected);
})->with([
    // Two slots, one identity: both operations are emitted, but a differ pairs them as one node.
    'renamed path parameter' => ['/api/users/{id}', 'op:v1:aaaaaaaaaaaaaaaa', ['identity.duplicate-operation']],
    // One slot, two identities: the document loses an operation, and nothing about identity is wrong.
    'two hosts on one URI' => ['/api/users/{user}', 'op:v1:bbbbbbbbbbbbbbbb', ['paths.operation-collision']],
    // One slot AND one identity is ONE event: the identity repeats because the path and method do, so
    // the collision report already names it. Saying it twice, thirteen lines apart, is one defect
    // reported as two.
    'one slot and one identity' => ['/api/users/{user}', 'op:v1:aaaaaaaaaaaaaaaa', ['paths.operation-collision']],
]);

it('does not advise a plain duplicate about hosts it does not have', function (): void {
    // The same route registered twice is one signature twice. The host advice is right for two hosts on
    // one URI and nonsense for this, and it used to be unconditional.
    [, $duplicate] = ($this->assemble)([
        ($this->fragment)('/api/reports', 'get', 'GET /api/reports', 'op:v1:aaaaaaaaaaaaaaaa'),
        ($this->fragment)('/api/reports', 'get', 'GET /api/reports', 'op:v1:aaaaaaaaaaaaaaaa'),
    ]);

    [, $hosts] = ($this->assemble)([
        ($this->fragment)('/api/reports', 'get', 'GET a.example.com/api/reports', 'op:v1:aaaaaaaaaaaaaaaa'),
        ($this->fragment)('/api/reports', 'get', 'GET b.example.com/api/reports', 'op:v1:bbbbbbbbbbbbbbbb'),
    ]);

    $help = static fn (array $diagnostics): ?string => array_values(array_filter(
        $diagnostics,
        static fn (Diagnostic $d): bool => $d->code === 'paths.operation-collision',
    ))[0]->help;

    expect($help($duplicate))->toContain('registered twice')
        ->and($help($duplicate))->not->toContain('host')
        ->and($help($hosts))->toContain('host');
});

it('reports nothing when every fragment holds a slot of its own', function (): void {
    // The negative path decides whether this is usable: a collision report on an ordinary app is noise
    // on every build.
    [$document, $diagnostics] = ($this->assemble)([
        ($this->fragment)('/api/reports', 'get', 'GET /api/reports', 'op:v1:aaaaaaaaaaaaaaaa'),
        ($this->fragment)('/api/reports', 'post', 'POST /api/reports', 'op:v1:bbbbbbbbbbbbbbbb'),
        ($this->fragment)('/api/ledgers', 'get', 'GET /api/ledgers', 'op:v1:cccccccccccccccc'),
    ]);

    expect(array_filter($diagnostics, fn (Diagnostic $d): bool => $d->code === 'paths.operation-collision'))->toBe([])
        ->and($document['paths']['/api/reports'])->toHaveKeys(['get', 'post'])
        ->and($document['paths']['/api/ledgers'])->toHaveKey('get');
});
