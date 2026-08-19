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
 * A webhook fragment is an ordinary operation fragment under a different heading: it lands under its
 * NAME in `webhooks` rather than under a path template, and the two headings never share a slot.
 */
beforeEach(function (): void {
    $this->fragment = static function (string $key, string $method, string $signature, bool $webhook): OperationFragment {
        $draft = new OperationDraft;
        $draft->setSummary($signature, Contribution::fallback());
        $draft->assignId('op:v1:'.substr(hash('sha256', $signature), 0, 16));

        return new OperationFragment($key, $method, $draft->freeze(), $signature, webhook: $webhook);
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

it('publishes a webhook fragment under webhooks and a route fragment under paths', function (): void {
    [$document] = ($this->assemble)([
        ($this->fragment)('/api/forms', 'get', 'GET /api/forms', false),
        ($this->fragment)('form.submitted', 'post', 'POST webhooks.form.submitted', true),
    ]);

    expect(array_keys($document['paths']))->toBe(['/api/forms'])
        ->and(array_keys($document['webhooks']))->toBe(['form.submitted'])
        ->and($document['webhooks']['form.submitted']['post']['summary'])->toBe('POST webhooks.form.submitted');
});

it('leaves no webhooks member behind when the document publishes none', function (): void {
    [$document] = ($this->assemble)([($this->fragment)('/api/forms', 'get', 'GET /api/forms', false)]);

    expect($document)->not->toHaveKey('webhooks');
});

it('keeps a webhook and a path template of the same name in separate slots', function (): void {
    [$document] = ($this->assemble)([
        ($this->fragment)('/forms', 'post', 'POST /forms', false),
        ($this->fragment)('/forms', 'post', 'POST webhooks./forms', true),
    ]);

    expect($document['paths']['/forms']['post']['summary'])->toBe('POST /forms')
        ->and($document['webhooks']['/forms']['post']['summary'])->toBe('POST webhooks./forms');
});

it('keeps the first claimant of a webhook name and method, and reports the one it could not emit', function (): void {
    [$document, $diagnostics] = ($this->assemble)([
        ($this->fragment)('form.submitted', 'post', 'POST webhooks.form.submitted', true),
        ($this->fragment)('form.submitted', 'post', 'POST webhooks.form.submitted (second)', true),
    ]);

    $collisions = array_values(array_filter($diagnostics, fn (Diagnostic $d): bool => $d->code === 'webhook.operation-collision'));

    expect($document['webhooks']['form.submitted']['post']['summary'])->toBe('POST webhooks.form.submitted')
        ->and($collisions)->toHaveCount(1)
        // Error, not a warning: a contract the API publishes is missing from the document.
        ->and($collisions[0]->severity->value)->toBe('error')
        ->and($collisions[0]->routeSignature)->toBe('POST webhooks.form.submitted (second)')
        ->and($collisions[0]->message)->toContain('form.submitted');
});

it('lets one webhook name carry several methods', function (): void {
    [$document, $diagnostics] = ($this->assemble)([
        ($this->fragment)('form.submitted', 'post', 'POST webhooks.form.submitted', true),
        ($this->fragment)('form.submitted', 'put', 'PUT webhooks.form.submitted', true),
    ]);

    expect(array_keys($document['webhooks']['form.submitted']))->toBe(['post', 'put'])
        ->and(array_filter($diagnostics, fn (Diagnostic $d): bool => $d->code === 'webhook.operation-collision'))->toBe([]);
});

it('reports an operationId two webhooks claim, as it does for two routes', function (): void {
    $first = ($this->fragment)('form.submitted', 'post', 'POST webhooks.form.submitted', true);
    $second = ($this->fragment)('form.deleted', 'post', 'POST webhooks.form.deleted', true);

    $draft = new OperationDraft;
    $draft->setOperationId('formEvent', Contribution::fallback());
    $draft->assignId('op:v1:1111111111111111');
    $shared = new OperationFragment('form.deleted', 'post', $draft->freeze(), 'POST webhooks.form.deleted', webhook: true);

    $withId = new OperationDraft;
    $withId->setOperationId('formEvent', Contribution::fallback());
    $withId->assignId('op:v1:2222222222222222');

    [, $diagnostics] = ($this->assemble)([
        new OperationFragment($first->path, 'post', $withId->freeze(), $first->routeSignature, webhook: true),
        $shared,
        $second,
    ]);

    expect(array_values(array_filter($diagnostics, fn (Diagnostic $d): bool => $d->code === 'route.duplicate-operation-id')))
        ->toHaveCount(1);
});
