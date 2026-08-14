<?php

declare(strict_types=1);

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Patch\Contribution;

/**
 * HTTP forbids content on 1xx, 204, 205 and 304 (RFC 9110), so a response under one of those statuses
 * must freeze without a body whichever producer aimed one at it. Every table entry is covered here,
 * alongside statuses that may carry a body and near-miss keys that must not match.
 */
it('drops content aimed at a status HTTP forbids a body on', function (string $status): void {
    $draft = new OperationDraft;

    $response = $draft->response($status);
    $response->setDescription('No Content', Contribution::fallback());
    // `response()->json(null, 204)` folds to a null payload — a schema, so it reaches content().
    $response->content('application/json')->set('type', 'null', Contribution::inference());
    $response->setExample('application/json', ['dropped' => true]);

    $frozen = $draft->freeze()->responses[$status];

    expect($frozen->content)->toBeNull()
        ->and($frozen->description)->toBe('No Content')
        ->and($response->hasContent('application/json'))->toBeFalse()
        // No media type means no response identity either — same as a `noContent()` 204 always had.
        ->and($response->primaryMediaType())->toBe('');
})->with(['100', '101', '102', '103', '199', '1XX', '204', '205', '304']);

it('keeps content on a status that may carry a body', function (string $status): void {
    $draft = new OperationDraft;

    $response = $draft->response($status);
    $response->content('application/json')->set('type', 'object', Contribution::inference());
    $response->setExample('application/json', ['kept' => true]);

    $content = $draft->freeze()->responses[$status]->content ?? [];

    expect($content['application/json']['schema']['type'] ?? null)->toBe('object')
        ->and($content['application/json']['example'] ?? null)->toBe(['kept' => true])
        ->and($response->primaryMediaType())->toBe('application/json');
})->with(['200', '201', '202', '203', '206', '300', '302', '400', '404', '422', '429', '500', '2XX', '3XX', 'default']);

it('hands out one detached draft per media type, so a producer writing twice writes to one place', function (): void {
    $response = (new OperationDraft)->response('204');

    expect($response->content('application/json'))->toBe($response->content('application/json'))
        ->and($response->content('application/json'))->not->toBe($response->content('application/xml'));
});

it('treats an unrecognised status key as body-carrying rather than guessing', function (string $status): void {
    $draft = new OperationDraft;
    $draft->response($status)->content('application/json')->set('type', 'object', Contribution::inference());

    expect($draft->freeze()->responses[$status]->content)->not->toBeNull();
    // '204\n' is the anchoring trap: an unanchored `$` would match it and silently drop the body.
})->with(['1', '11', '1XXX', 'x204', "204\n", '0204', '2040', 'unknown']);
