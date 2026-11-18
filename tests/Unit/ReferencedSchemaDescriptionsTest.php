<?php

declare(strict_types=1);

use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Extensions\BuiltIn\ReferencedSchemaDescriptions;
use Docuccino\Core\Patch\Contribution;

/**
 * Every response naming one schema says something about the same thing, so what they say is settled over
 * the whole document: one sentence is published on the schema, two different ones publish neither and say
 * so. The sentences ride each response rather than the schema, so this is the one place they meet — and
 * the answer may never depend on which route was read first.
 */
beforeEach(function (): void {
    // A response whose JSON body is the `Challenge` schema, with the sentences given said about it.
    $this->response = static function (array $sentences = []): array {
        $response = ['description' => 'Unprocessable', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Challenge']]]];
        if ($sentences !== []) {
            $response['x-docuccino'] = ['facts' => [ResponseDraft::SCHEMA_DESCRIPTIONS => ['application/json' => $sentences]]];
        }

        return $response;
    };

    $this->doc = static fn (array $operations, array $components = []): array => [
        'openapi' => '3.2.0',
        'paths' => array_map(static fn (array $response): array => ['post' => ['responses' => ['422' => $response]]], $operations),
        'components' => ['schemas' => ['Challenge' => ['type' => 'object']], ...$components],
    ];
});

it('publishes the one sentence the responses naming a schema agree on, and removes the facts', function (array $first, array $second): void {
    [$doc, $diagnostics] = ReferencedSchemaDescriptions::settle(($this->doc)([
        '/login' => ($this->response)($first),
        '/mfa' => ($this->response)($second),
    ]));

    expect($doc['components']['schemas']['Challenge'])->toBe(['type' => 'object', 'description' => 'One more step.'])
        ->and($doc['paths']['/login']['post']['responses']['422'])->not->toHaveKey('x-docuccino.facts')
        ->and($doc['paths']['/mfa']['post']['responses']['422'])->not->toHaveKey('x-docuccino.facts')
        ->and($diagnostics)->toBe([]);
})->with([
    'said on one, silent on the other' => [['One more step.'], []],
    'said alike on both' => [['One more step.'], ['One more step.']],
]);

it('publishes no sentence where the responses disagree, and names both and where each was said', function (): void {
    $operations = [
        '/login' => ($this->response)(['One more step.']),
        '/mfa' => ($this->response)(['A second factor is needed.']),
        '/step-up' => ($this->response)(['One more step.']),
    ];

    [$doc, $diagnostics] = ReferencedSchemaDescriptions::settle(($this->doc)($operations));
    // The same responses met in the other order: the answer is a function of the set.
    [$reversed, $reversedDiagnostics] = ReferencedSchemaDescriptions::settle(($this->doc)(array_reverse($operations, true)));

    expect($doc['components']['schemas']['Challenge'])->toBe(['type' => 'object'])
        ->and($reversed['components']['schemas']['Challenge'])->toBe(['type' => 'object'])
        ->and(array_map(static fn ($d): string => $d->code, $diagnostics))->toBe(['components.schema-description-conflict'])
        ->and($diagnostics[0]->message)->toBe('Schema "Challenge" is described differently by the responses naming it — "A second factor is needed." (POST /mfa 422); "One more step." (POST /login 422, POST /step-up 422) — so it is published with no description.')
        ->and($reversedDiagnostics[0]->message)->toBe($diagnostics[0]->message)
        ->and($diagnostics[0]->routeSignature)->toBeNull();
});

it('follows a response the shared-error hoist made a reference to the component it names', function (): void {
    $use = ['$ref' => '#/components/responses/Challenge', 'x-docuccino' => ['facts' => [ResponseDraft::SCHEMA_DESCRIPTIONS => ['application/json' => ['One more step.']]]]];

    [$doc] = ReferencedSchemaDescriptions::settle(($this->doc)(['/login' => $use], ['responses' => ['Challenge' => ($this->response)()]]));

    expect($doc['components']['schemas']['Challenge']['description'])->toBe('One more step.')
        ->and($doc['paths']['/login']['post']['responses']['422'])->toBe(['$ref' => '#/components/responses/Challenge', 'x-docuccino' => []]);
});

it('describes nothing where the body is no pointer to a schema, and still removes the fact', function (): void {
    $inline = ['description' => 'Unprocessable', 'content' => ['application/json' => ['schema' => ['type' => 'object']]], 'x-docuccino' => [
        'id' => 'res:v1:aaaaaaaaaaaaaaaa',
        'facts' => [ResponseDraft::SCHEMA_DESCRIPTIONS => ['application/json' => ['One more step.']], ResponseDraft::STATUS_UNPLACED => true],
    ]];

    [$doc, $diagnostics] = ReferencedSchemaDescriptions::settle(($this->doc)(['/login' => $inline]));

    expect($doc['components']['schemas']['Challenge'])->toBe(['type' => 'object'])
        // Only its own fact goes: the response's identity and its other facts are someone else's.
        ->and($doc['paths']['/login']['post']['responses']['422']['x-docuccino'])->toBe(['id' => 'res:v1:aaaaaaaaaaaaaaaa', 'facts' => [ResponseDraft::STATUS_UNPLACED => true]])
        ->and($diagnostics)->toBe([]);
});

it('writes the sentence over a description the schema already had', function (): void {
    // The schema a declaration names is the author's to describe; anything a producer wrote there before
    // was about a body nobody named.
    $doc = ($this->doc)(['/login' => ($this->response)(['One more step.'])]);
    $doc['components']['schemas']['Challenge']['description'] = 'Either of two shapes.';

    expect(ReferencedSchemaDescriptions::settle($doc)[0]['components']['schemas']['Challenge']['description'])->toBe('One more step.');
});

it('records every sentence said about a body, keeps them through an absorbed response, and ignores an empty one', function (): void {
    $response = new ResponseDraft('422');
    $response->content('application/json')->set('type', 'object', Contribution::attribute());
    $response->describeReferencedSchema('application/json', 'One more step.');
    $response->describeReferencedSchema('application/json', '');

    $other = new ResponseDraft('422');
    $other->describeReferencedSchema('application/json', 'A second factor is needed.');
    $other->describeReferencedSchema('application/json', 'One more step.');
    $response->absorb($other);

    expect($response->freeze()->toArray()['x-docuccino']['facts'][ResponseDraft::SCHEMA_DESCRIPTIONS])
        ->toBe(['application/json' => ['A second factor is needed.', 'One more step.']]);
});
