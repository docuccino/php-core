<?php

declare(strict_types=1);

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Patch\Contribution;

/**
 * The draft half of named examples: where a declared map and a declared singular land, what they
 * displace, and that a map is a function of the names in it rather than of the order they arrived.
 */
it('emits a declared examples map beside the schema, name-sorted', function (): void {
    $response = new ResponseDraft('200');
    $response->content('application/json')->set('type', 'object', Contribution::inference());

    $response->declareExamples('application/json', ['zulu' => ['value' => 26]]);
    $response->declareExamples('application/json', ['alpha' => ['value' => 1], 'mike' => ['value' => 13]]);

    $content = $response->freeze()->content ?? [];

    expect(array_keys($content['application/json']['examples']))->toBe(['alpha', 'mike', 'zulu'])
        ->and($content['application/json'])->not->toHaveKey('example');
});

it('keeps the first declaration of a name, so a later one cannot rewrite it', function (): void {
    $response = new ResponseDraft('200');
    $response->content('application/json')->set('type', 'object', Contribution::inference());

    $response->declareExamples('application/json', ['one' => ['value' => 'first']]);
    $response->declareExamples('application/json', ['one' => ['value' => 'second']]);

    $content = $response->freeze()->content ?? [];

    expect($content['application/json']['examples']['one'])->toBe(['value' => 'first']);
});

it('lets a declared singular displace the illustrative one a producer set', function (): void {
    $response = new ResponseDraft('200');
    $response->content('application/json')->set('type', 'object', Contribution::inference());

    $response->setExample('application/json', ['inferred' => true]);
    $response->declareExamples('application/json', [], ['declared' => true]);

    $content = $response->freeze()->content ?? [];

    expect($content['application/json']['example'])->toBe(['declared' => true]);
});

it('lets a declared map displace a declared singular, which OAS makes exclusive of it', function (): void {
    $response = new ResponseDraft('200');
    $response->content('application/json')->set('type', 'object', Contribution::inference());

    $response->setExample('application/json', ['inferred' => true]);
    $response->declareExamples('application/json', ['named' => ['value' => 1]], ['bare' => true]);

    $content = $response->freeze()->content ?? [];

    expect($content['application/json'])->toHaveKey('examples')
        ->and($content['application/json'])->not->toHaveKey('example');
});

it('drops examples declared for a media type the response never carried', function (): void {
    $response = new ResponseDraft('200');
    $response->content('application/json')->set('type', 'object', Contribution::inference());

    $response->declareExamples('application/xml', ['named' => ['value' => 1]]);

    $content = $response->freeze()->content ?? [];

    expect(array_keys($content))->toBe(['application/json'])
        ->and($content['application/json'])->not->toHaveKey('examples');
});

it('replaces a parameter example with the declared map, which cannot sit beside it', function (): void {
    $draft = new OperationDraft;
    $parameter = $draft->parameter('query', 'q');
    $parameter->set('example', 'from the parameter attribute', Contribution::attribute());
    $parameter->declareExamples(['zulu' => ['value' => 'z'], 'alpha' => ['value' => 'a']]);

    $frozen = $draft->freeze()->parameters[0];

    expect(array_keys($frozen->rest['examples']))->toBe(['alpha', 'zulu'])
        ->and($frozen->rest)->not->toHaveKey('example');
});

it('leaves a parameter example alone when nothing declared a map', function (): void {
    $draft = new OperationDraft;
    $draft->parameter('query', 'q')->set('example', 'kept', Contribution::attribute());

    expect($draft->freeze()->parameters[0]->rest['example'])->toBe('kept');
});

it('folds request-body examples into the body whoever wrote it left behind', function (): void {
    $draft = new OperationDraft;
    $draft->set('requestBody', [
        'required' => true,
        'content' => ['application/json' => ['schema' => ['type' => 'object']]],
    ], Contribution::integration('validation'));

    $draft->declareRequestBodyExamples('application/json', ['zulu' => ['value' => 26]]);
    $draft->declareRequestBodyExamples('application/json', ['alpha' => ['value' => 1]]);

    $body = $draft->freeze()->rest['requestBody'];

    expect($body['required'])->toBeTrue()
        ->and($body['content']['application/json']['schema'])->toBe(['type' => 'object'])
        ->and(array_keys($body['content']['application/json']['examples']))->toBe(['alpha', 'zulu']);
});

it('folds a request-body singular in the same place, and lets a map displace it', function (): void {
    $draft = new OperationDraft;
    $draft->set('requestBody', [
        'content' => ['application/json' => ['schema' => ['type' => 'object']]],
    ], Contribution::integration('validation'));

    $draft->declareRequestBodyExamples('application/json', [], ['bare' => true]);

    expect($draft->freeze()->rest['requestBody']['content']['application/json']['example'])->toBe(['bare' => true]);

    $draft->declareRequestBodyExamples('application/json', ['named' => ['value' => 1]]);
    $media = $draft->freeze()->rest['requestBody']['content']['application/json'];

    expect($media)->toHaveKey('examples')->and($media)->not->toHaveKey('example');
});

it('leaves a request body it cannot make sense of exactly as it found it', function (mixed $body): void {
    $draft = new OperationDraft;
    $draft->set('requestBody', $body, Contribution::integration('validation'));
    $draft->declareRequestBodyExamples('application/json', ['named' => ['value' => 1]]);

    expect($draft->freeze()->rest['requestBody'])->toBe($body);
})->with([
    'a $ref' => [['$ref' => '#/components/requestBodies/Widget']],
    'content that is not a map' => [['content' => 'nonsense']],
    'a media type that is not a map' => [['content' => ['application/json' => 'nonsense']]],
    'a body that is not a map' => ['nonsense'],
]);

it('leaves a body alone for a media type nothing declared examples for', function (): void {
    $draft = new OperationDraft;
    $draft->set('requestBody', [
        'content' => [
            'application/json' => ['schema' => ['type' => 'object']],
            'multipart/form-data' => ['schema' => ['type' => 'object']],
        ],
    ], Contribution::integration('validation'));

    $draft->declareRequestBodyExamples('application/json', ['named' => ['value' => 1]]);
    $content = $draft->freeze()->rest['requestBody']['content'];

    expect($content['application/json'])->toHaveKey('examples')
        ->and($content['multipart/form-data'])->toBe(['schema' => ['type' => 'object']]);
});

it('answers its response statuses byte-sorted, whatever order they were registered in', function (): void {
    $draft = new OperationDraft;
    foreach (['422', '200', '2XX', '404', '201'] as $status) {
        $draft->response($status);
    }

    expect($draft->responseStatuses())->toBe(['200', '201', '2XX', '404', '422']);
});
