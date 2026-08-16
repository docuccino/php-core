<?php

declare(strict_types=1);

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Draft\ResponseDraft;
use Docuccino\Core\Draft\SchemaDraft;
use Docuccino\Core\Extensions\Validation\ResponseDraftApplier;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Patch\PatchResult;
use Docuccino\Core\Patch\Remove;

it('freezes into the immutable Operation model with provenance and overrode', function (): void {
    $draft = (new OperationDraft)->assignId('op:v1:aaaaaaaaaaaaaaaa');

    $draft->setSummary('Index forms', Contribution::docblock());
    $draft->setSummary('List forms', Contribution::attribute());
    $draft->setOperationId('forms.index', Contribution::inference());

    $operation = $draft->freeze();

    expect($operation->summary)->toBe('List forms');
    expect($operation->operationId)->toBe('forms.index');
    expect($operation->docuccino?->id)->toBe('op:v1:aaaaaaaaaaaaaaaa');

    $provenance = $operation->docuccino?->provenance?->toArray() ?? [];
    $byLayer = [];
    foreach ($provenance as $record) {
        $byLayer[$record['layer']] = $record;
    }

    expect($byLayer['attribute']['fields'])->toBe(['summary']);
    expect($byLayer['attribute']['overrode'])->toBe([
        ['field' => 'summary', 'value' => 'Index forms', 'producer' => 'docblock'],
    ]);
    expect($byLayer['inference']['fields'])->toBe(['operationId']);
});

it('surfaces a shadowed write when a lower layer writes over a higher owner', function (): void {
    $draft = new OperationDraft;

    expect($draft->setSummary('Attribute wins', Contribution::attribute()))->toBe(PatchResult::Accepted);
    expect($draft->setSummary('Docblock loses', Contribution::docblock()))->toBe(PatchResult::Shadowed);

    expect($draft->freeze()->summary)->toBe('Attribute wins');
});

it('merges parameters by (in, name) rather than replacing the collection', function (): void {
    $draft = new OperationDraft;

    $status = $draft->parameter('query', 'status');
    $again = $draft->parameter('query', 'status');
    $perPage = $draft->parameter('query', 'per_page');

    expect($status)->toBe($again);
    expect($status)->not->toBe($perPage);

    $status->setRequired(false, Contribution::integration('spatie-query-builder'));
    $status->schema()->set('type', 'string', Contribution::integration('spatie-query-builder'));
    $perPage->setRequired(false, Contribution::inference());

    $operation = $draft->freeze();

    expect($operation->parameters)->toHaveCount(2);
    $names = array_map(static fn ($p) => $p->name, $operation->parameters);
    expect($names)->toContain('status')->toContain('per_page');
});

it('merges responses by status and content by media type', function (): void {
    $draft = new OperationDraft;

    $ok = $draft->response('200');
    $ok->setDescription('Paginated list', Contribution::inference());
    $ok->content('application/json')->set('type', 'object', Contribution::inference());
    $ok->content('application/json')->set('title', 'Forms', Contribution::attribute());
    $ok->content('application/xml')->set('type', 'object', Contribution::inference());

    expect($draft->response('200'))->toBe($ok);

    $operation = $draft->freeze();

    expect($operation->responses)->toHaveKey('200');
    $content = $operation->responses['200']->content ?? [];
    expect($content)->toHaveKey('application/json');
    expect($content)->toHaveKey('application/xml');
    expect($content['application/json']['schema']['type'])->toBe('object');
    expect($content['application/json']['schema']['title'])->toBe('Forms');
});

it('emits a media-type example beside the schema, first-writer-wins and only where a schema exists', function (): void {
    $draft = new OperationDraft;

    $response = $draft->response('403');
    $response->content('application/problem+json')->set('type', 'object', Contribution::inference());
    $response->setExample('application/problem+json', ['status' => 403, 'type' => 'about:blank']);
    // First writer wins — a later producer does not overwrite the established example.
    $response->setExample('application/problem+json', ['status' => 999]);
    // An example for a media type carrying no schema is dropped (nothing to attach it to).
    $response->setExample('text/plain', ['ignored' => true]);

    $content = $draft->freeze()->responses['403']->content ?? [];

    expect($content['application/problem+json']['example'])->toBe(['status' => 403, 'type' => 'about:blank'])
        ->and($content)->not->toHaveKey('text/plain');
});

it('merges schema properties by name, patching a sibling without discarding others', function (): void {
    $schema = new SchemaDraft;
    $schema->set('type', 'object', Contribution::inference());

    $schema->property('id')->set('type', 'integer', Contribution::inference());
    $schema->property('title')->set('type', 'string', Contribution::inference());
    // A later, higher layer patches only the title description.
    $schema->property('title')->set('description', 'The form title', Contribution::attribute());

    $frozen = $schema->freeze()->toArray();

    expect($frozen['properties'])->toHaveKey('id');
    expect($frozen['properties'])->toHaveKey('title');
    expect($frozen['properties']['id']['type'])->toBe('integer');
    expect($frozen['properties']['title']['type'])->toBe('string');
    expect($frozen['properties']['title']['description'])->toBe('The form title');
});

it('drops a field written with the Remove sentinel while keeping siblings', function (): void {
    $draft = new OperationDraft;
    $draft->setSummary('Kept', Contribution::inference());
    $draft->setDeprecated(true, Contribution::inference());
    $draft->set('deprecated', Remove::value(), Contribution::attribute());

    $operation = $draft->freeze();

    expect($operation->summary)->toBe('Kept');
    expect($operation->deprecated)->toBeNull();
    expect($operation->toArray())->not->toHaveKey('deprecated');
});

it('carries a parameter x-docuccino semantic fact through freeze alongside id/provenance', function (): void {
    $draft = new OperationDraft;
    $parameter = $draft->parameter('path', 'form');
    $parameter->assignId('par:v1:bbbbbbbbbbbbbbbb');
    $parameter->schema()->set('type', 'integer', Contribution::inference());
    $parameter->setDocuccinoFact('routeBinding', ['withTrashed' => true]);

    $frozen = $parameter->freeze()->toArray();

    expect($frozen['x-docuccino']['id'])->toBe('par:v1:bbbbbbbbbbbbbbbb')
        ->and($frozen['x-docuccino']['facts']['routeBinding'])->toBe(['withTrashed' => true]);
});

it('carries schema mock hints through freeze into x-docuccino.mock', function (): void {
    $schema = (new SchemaDraft)->assignMock(['faker' => 'numberBetween:1,100']);
    $schema->set('type', 'integer', Contribution::inference());

    $frozen = $schema->freeze()->toArray();

    expect($frozen['x-docuccino']['mock'])->toBe(['faker' => 'numberBetween:1,100']);
});

it('exposes winning value + producer through the public read accessors (B1)', function (): void {
    $draft = new OperationDraft;
    $draft->setSummary('Docblock summary', Contribution::docblock());
    $draft->setSummary('Attribute summary', Contribution::attribute());

    // resolvedField returns the winning value; producerFor names its layer producer; both null when unset.
    expect($draft->resolvedField('summary'))->toBe('Attribute summary')
        ->and($draft->producerFor('summary'))->toBe('attribute')
        ->and($draft->resolvedField('operationId'))->toBeNull()
        ->and($draft->producerFor('operationId'))->toBeNull();

    // A Remove sentinel resolves to null through resolvedField (sentinel omitted, not surfaced).
    $draft->set('deprecated', Remove::value(), Contribution::overlay());
    expect($draft->resolvedField('deprecated'))->toBeNull();
});

it('carries a declared component name through freeze as an x-docuccino fact', function (): void {
    $draft = new ResponseDraft('404');

    $draft->setDescription('Not Found', Contribution::integration('framework-errors'));
    $draft->claimComponentName('NotFound', Contribution::integration('framework-errors'));

    $frozen = $draft->freeze();

    expect($draft->componentClaim())->toBe('NotFound')
        ->and($frozen->docuccino?->rest)->toBe(['facts' => ['component' => 'NotFound']])
        // The claim is not a response member: it travels under `x-docuccino`, never beside `description`.
        ->and($frozen->rest)->toBe([])
        ->and($frozen->toArray()['x-docuccino']['facts'])->toBe(['component' => 'NotFound']);
});

it('settles two producers naming one response by precedence, not by order', function (): void {
    // A declared name is guarded like every other field, so the layer that owns the body owns its name.
    $lowFirst = new ResponseDraft('404');
    $lowFirst->claimComponentName('Fallback', Contribution::forProducer('fallback'));
    $lowFirst->claimComponentName('NotFound', Contribution::integration('framework-errors'));

    $highFirst = new ResponseDraft('404');
    $highFirst->claimComponentName('NotFound', Contribution::integration('framework-errors'));

    expect($highFirst->claimComponentName('Fallback', Contribution::forProducer('fallback')))
        ->toBe(PatchResult::Shadowed)
        ->and($lowFirst->componentClaim())->toBe('NotFound')
        ->and($highFirst->componentClaim())->toBe('NotFound');
});

it('declares nothing when a producer has no name to give', function (): void {
    $draft = new ResponseDraft('402');

    expect($draft->claimComponentName(null, Contribution::forProducer('fallback')))->toBe(PatchResult::NoOp)
        ->and($draft->componentClaim())->toBeNull()
        ->and($draft->freeze()->docuccino)->toBeNull();
});

it('reads a name no component key could carry as no declaration at all', function (string $case, string $name): void {
    // Enforced at the write, so the fact never reaches the document and the answer does not depend on
    // whether the hoist — the only thing that would have refused it later — is switched on. The body
    // falls back to its status, which is what an undeclared body was always going to publish under.
    $draft = new ResponseDraft('404');

    expect($draft->claimComponentName($name, Contribution::integration('acme')))->toBe(PatchResult::NoOp)
        ->and($draft->componentClaim())->toBeNull()
        ->and($draft->freeze()->docuccino)->toBeNull();
})->with([
    ['a space', 'Not Found'],
    ['punctuation', 'Not Found!'],
    ['a namespace separator', 'App\\Errors\\NotFound'],
    ['a slash, which would leave the pointer', 'errors/NotFound'],
    ['an escape sequence and a newline', "Evil\x1b[31m\nName"],
    ['nothing but illegal characters', '!!!'],
    ['the empty string', ''],
]);

it('keeps a legal name whatever it is spelled with', function (string $case, string $name): void {
    // The other half of the contract: the character class is `ComponentNames`', not a second opinion,
    // so everything a `$ref` can carry goes through untouched.
    $draft = new ResponseDraft('404');

    expect($draft->claimComponentName($name, Contribution::integration('acme')))->toBe(PatchResult::Accepted)
        ->and($draft->componentClaim())->toBe($name);
})->with([
    ['a reason phrase', 'NotFound'],
    ['a dotted name', 'acme.NotFound'],
    ['an underscored name', 'Not_Found'],
    ['a hyphenated name', 'Not-Found'],
    ['digits', 'Error404'],
]);

it('carries a declared name across the merge into the operation it applies to', function (): void {
    // The applier is where a mapper's draft becomes the operation's response, so it is where the claim
    // has to survive — the hoist reads it off the finished document and nowhere else.
    $operation = new OperationDraft;

    $mapped = new ResponseDraft('404');
    $mapped->setDescription('Not Found', Contribution::integration('framework-errors'));
    $mapped->claimComponentName('NotFound', Contribution::integration('framework-errors'));
    $mapped->content('application/json')->set('type', 'object', Contribution::integration('framework-errors'));

    (new ResponseDraftApplier)->apply($operation, $mapped, 'integration:framework-errors');

    $frozen = $operation->freeze()->responses['404'] ?? null;

    expect($frozen?->toArray()['x-docuccino']['facts'])->toBe(['component' => 'NotFound']);
});
