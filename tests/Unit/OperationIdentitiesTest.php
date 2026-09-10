<?php

declare(strict_types=1);

use Docuccino\Core\Document\Operation;
use Docuccino\Core\Identity\IdentityGenerator;
use Docuccino\Core\Identity\OperationIdentities;

/**
 * The one site that mints an operation's identity tree. It runs after the fragment cache, so what it
 * must never do is change anything else — a member it forgets to carry is a member a warm build loses.
 */
function operationToStamp(): Operation
{
    return Operation::fromArray([
        'operationId' => 'listForms',
        'summary' => 'List forms',
        'description' => 'Every form.',
        'deprecated' => true,
        'tags' => ['Forms'],
        'security' => [['bearer' => []]],
        'x-docuccino' => ['provenance' => [['fields' => ['summary'], 'producer' => 'docblock']], 'mock' => ['seed' => 1]],
        'parameters' => [
            ['name' => 'page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer'], 'style' => 'form'],
            ['name' => 'form', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
        ],
        'responses' => [
            '200' => ['description' => 'OK', 'content' => ['application/json' => ['schema' => ['type' => 'object']], 'text/csv' => ['schema' => ['type' => 'string']]]],
            '404' => ['$ref' => '#/components/responses/NotFound'],
        ],
        'requestBody' => ['content' => ['application/json' => ['schema' => ['type' => 'object']]]],
        'x-vendor' => ['kept' => true],
    ]);
}

/**
 * An operation with every node id taken out — and with an `x-docuccino` left holding nothing taken
 * out too, since that is what the models publish when there was never an id to begin with.
 */
function operationWithoutNodeIds(array $node): array
{
    unset($node['id']);

    foreach ($node as $key => $value) {
        if (! is_array($value)) {
            continue;
        }

        $stripped = operationWithoutNodeIds($value);
        if ($key === 'x-docuccino' && $stripped === []) {
            unset($node[$key]);

            continue;
        }

        $node[$key] = $stripped;
    }

    return $node;
}

it('changes the ids and nothing else', function (): void {
    $before = operationToStamp();
    $after = (new OperationIdentities)->stamp($before, 'op:v1:aaaaaaaaaaaaaaaa');

    // The before-and-after masks must differ from the raw arrays, or a mask that stopped matching
    // would make this a comparison of two untouched documents.
    expect($after->toArray())->not->toBe($before->toArray())
        ->and(operationWithoutNodeIds($after->toArray()))->toBe(operationWithoutNodeIds($before->toArray()))
        ->and(operationWithoutNodeIds($after->toArray()))->not->toBe($after->toArray());
});

it('mints each child from the tuple the identity spec names', function (): void {
    $identity = new IdentityGenerator;
    $operationId = 'op:v1:aaaaaaaaaaaaaaaa';
    $stamped = (new OperationIdentities)->stamp(operationToStamp(), $operationId)->toArray();

    expect($stamped['x-docuccino']['id'])->toBe($operationId)
        ->and($stamped['parameters'][0]['x-docuccino']['id'])->toBe($identity->parameterId($operationId, 'query', 'page'))
        ->and($stamped['parameters'][1]['x-docuccino']['id'])->toBe($identity->parameterId($operationId, 'path', 'form'))
        // The FIRST media type is the one a response is identified by, so a second one beside it moves
        // nothing.
        ->and($stamped['responses']['200']['x-docuccino']['id'])->toBe($identity->responseId($operationId, '200', 'application/json'));
});

it('leaves a response naming no media type without an id rather than minting one from nothing', function (): void {
    $stamped = (new OperationIdentities)->stamp(operationToStamp(), 'op:v1:aaaaaaaaaaaaaaaa')->toArray();

    expect($stamped['responses']['404'])->not->toHaveKey('x-docuccino');
});

it('restamps an operation that already carries another document ids', function (): void {
    $identities = new OperationIdentities;
    $first = $identities->stamp(operationToStamp(), 'op:v1:aaaaaaaaaaaaaaaa');
    $second = $identities->stamp($first, 'op:v1:bbbbbbbbbbbbbbbb');

    // A fragment carries no id today, but nothing in the stamp depends on that: a second document's
    // stamp replaces the first's everywhere rather than keeping whichever got there first.
    expect($second->toArray()['x-docuccino']['id'])->toBe('op:v1:bbbbbbbbbbbbbbbb')
        ->and($second->toArray()['parameters'][0]['x-docuccino']['id'])
        ->not->toBe($first->toArray()['parameters'][0]['x-docuccino']['id'])
        ->and(operationWithoutNodeIds($second->toArray()))->toBe(operationWithoutNodeIds($first->toArray()));
});
