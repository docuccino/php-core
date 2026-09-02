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

/**
 * `Shadowed` is a return value nothing in the build reads, so the trail is the only place a discarded
 * value can be answered for. This walks it all the way to the frozen node an emitter serialises.
 */
it('carries a shadowed value onto the frozen node so the emitted trail can answer for it', function (): void {
    $draft = new OperationDraft;

    $draft->setSummary('Attribute wins', Contribution::attribute());
    $draft->setSummary('Docblock loses', Contribution::docblock());

    $records = $draft->freeze()->docuccino?->provenance?->toArray() ?? [];

    expect($records)->toHaveCount(1)
        ->and($records[0]['producer'])->toBe('attribute')
        ->and($records[0]['overrode'])->toBe([
            ['field' => 'summary', 'value' => 'Docblock loses', 'producer' => 'docblock'],
        ]);
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

/**
 * The keys are a function of the parameters and never of the order the producers wrote them. That is
 * not decoration: they reach a diagnostic that rides the fragment cache, so an order-dependent answer
 * makes a warm build report something a cold one did not. {@see OperationDraft::responseStatuses()}
 * has had a pin like this since it was written; this had none, and replacing its `sort()` with
 * `array_reverse()` passed the entire unit suite.
 */
it('answers its parameter keys in byte order whatever order the producers wrote them', function (): void {
    $registrations = [['query', 'status'], ['path', 'invoice'], ['query', 'per_page'], ['header', 'X-Tenant']];

    $asWritten = new OperationDraft;
    foreach ($registrations as [$in, $name]) {
        $asWritten->parameter($in, $name);
    }

    // The same set met the other way round: neither order is the sorted one, so a producer order that
    // travelled through would fail here whichever end it came out of.
    $reversed = new OperationDraft;
    foreach (array_reverse($registrations) as [$in, $name]) {
        $reversed->parameter($in, $name);
    }

    $keys = ['header:X-Tenant', 'path:invoice', 'query:per_page', 'query:status'];

    expect($asWritten->parameterKeys())->toBe($keys)
        ->and($reversed->parameterKeys())->toBe($keys);
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

it('freezes a parameter that got no schema contributions with an explicit empty schema', function (): void {
    $draft = new OperationDraft;
    $parameter = $draft->parameter('query', 'filter[opaque]');
    $parameter->setDescription('Custom filter', Contribution::integration('query-builder'));

    $frozen = $parameter->freeze()->toArray();

    // OAS 3.x requires a parameter to state `schema` or `content` — an untyped one publishes the
    // unconstrained {} rather than dropping the member and invalidating the document.
    expect($frozen)->toHaveKey('schema')
        ->and($frozen['schema'])->toBe([]);
});

it('omits the schema when a parameter states its shape through content instead', function (): void {
    $draft = new OperationDraft;
    $parameter = $draft->parameter('query', 'complex');
    $parameter->set('content', ['application/json' => ['schema' => ['type' => 'string']]], Contribution::attribute());

    expect($parameter->freeze()->toArray())->not->toHaveKey('schema');
});

it('omits the schema when a parameter states its shape through a $ref instead', function (): void {
    $draft = new OperationDraft;
    $parameter = $draft->parameter('query', 'shared');
    $parameter->set('$ref', '#/components/parameters/Shared', Contribution::attribute());

    expect($parameter->freeze()->toArray())->not->toHaveKey('schema');
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

it('carries a media type a producer named and constrained in no way', function (): void {
    // "A body of this media type, shape unknown" is a producer saying something, and JSON Schema spells
    // it with an EMPTY schema. A merge driven only by the keyword loop finds nothing to copy and would
    // leave the response with no `content` at all — which says the error returns NOTHING, a different
    // claim entirely and one the producer never made.
    $operation = new OperationDraft;

    $mapped = new ResponseDraft('500');
    $mapped->setDescription('Internal Server Error', Contribution::integration('inferred-handler'));
    $mapped->content('application/problem+json');

    (new ResponseDraftApplier)->apply($operation, $mapped, 'integration:inferred-handler');

    $frozen = $operation->freeze()->responses['500']->toArray();

    expect($frozen['content'] ?? null)->toBe(['application/problem+json' => ['schema' => []]]);
});

it('retracts the redirect range a declared concrete status supersedes', function (): void {
    // The range key stands in for the ONE status nobody named. Once something names it, publishing both
    // would tell a consumer that any other member of the class may happen too — which is exactly what
    // the declaration denied.
    $draft = new OperationDraft;

    $range = $draft->response('3XX');
    $range->setDescription('Redirect', Contribution::fallback());
    $range->set('headers', ['Location' => ['schema' => ['type' => 'string']]], Contribution::inference());

    $draft->response('302')->setDescription('Follow the Location header.', Contribution::attribute());
    $draft->supersedeStatusRange('302', Contribution::attribute());

    $frozen = $draft->freeze()->responses['302'];

    expect($draft->responseStatuses())->toBe(['302'])
        ->and($frozen->description)->toBe('Follow the Location header.')
        ->and($frozen->headers)->toBe(['Location' => ['schema' => ['type' => 'string']]]);
});

it('reparents every fact the retired range held, at the layer that wrote it', function (): void {
    // Standing in for the unknown code was all the range was for, so it never owned what it collected:
    // a body, its examples and a component claim reach the declared status with their provenance, and
    // whatever the declaration already states at a higher layer stays the declaration's.
    $draft = new OperationDraft;

    $range = $draft->response('3XX');
    $range->setDescription('Redirect', Contribution::fallback());
    $range->claimComponentName('Redirected', Contribution::inference());
    $range->content('application/json')->set('type', 'object', Contribution::inference());
    $range->content('application/json')->property('url')->set('type', 'string', Contribution::inference());
    $range->declareExamples('application/json', ['moved' => ['value' => ['url' => '/a']]]);
    $range->illustrateExamples('application/json', ['recorded' => ['value' => ['url' => '/b']]]);
    $range->content('text/plain')->set('type', 'string', Contribution::inference());
    $range->declareExamples('text/plain', [], '/a');
    $range->setExample('text/plain', '/ignored-in-favour-of-the-declaration');

    $draft->response('303')->setDescription('See Other', Contribution::attribute());
    $draft->supersedeStatusRange('303', Contribution::attribute());

    $frozen = $draft->freeze()->responses['303'];
    $json = $frozen->content['application/json'] ?? [];

    expect($draft->responseStatuses())->toBe(['303'])
        ->and($frozen->description)->toBe('See Other')
        ->and($frozen->toArray()['x-docuccino']['facts'])->toBe(['component' => 'Redirected'])
        ->and($json['schema']['properties']['url']['type'] ?? null)->toBe('string')
        ->and($json['schema']['properties']['url']['x-docuccino']['provenance'][0]['layer'] ?? null)->toBe('inference')
        ->and(array_keys($json['examples'] ?? []))->toBe(['moved', 'recorded'])
        ->and($frozen->content['text/plain']['example'] ?? null)->toBe('/a');
});

it('drops a reparented body the declared status may not carry', function (): void {
    // 304 is bodyless, and the bodyless rule is enforced at the write — so a range that collected a
    // body hands over everything but that, rather than smuggling one past it.
    $draft = new OperationDraft;

    $range = $draft->response('3XX');
    $range->set('headers', ['Location' => ['schema' => ['type' => 'string']]], Contribution::inference());
    $range->content('application/json')->set('type', 'object', Contribution::inference());

    $draft->supersedeStatusRange('304', Contribution::attribute());

    $frozen = $draft->freeze()->responses['304'];

    expect($frozen->content)->toBeNull()
        ->and($frozen->headers)->toHaveKey('Location');
});

it('leaves a range of another class alone when a status is declared', function (string $status): void {
    // A declared 200 or 404 says nothing about which redirect an endpoint answers with, so the honest
    // range survives beside it.
    $draft = new OperationDraft;
    $draft->response('3XX')->setDescription('Redirect', Contribution::fallback());
    $draft->response($status)->setDescription('Declared', Contribution::attribute());

    $draft->supersedeStatusRange($status, Contribution::attribute());

    expect($draft->responseStatuses())->toContain('3XX');
})->with(['success' => ['200'], 'not found' => ['404'], 'server error' => ['503']]);

it('retires no range but the redirect one', function (string $range, string $status): void {
    // An error range is a member set — "any 4xx answers like this" — so a declared member denies
    // nothing about the others and the range stays. Only 3XX stands for one unknown code.
    $draft = new OperationDraft;
    $draft->response($range)->setDescription('Problem', Contribution::inference());

    $draft->supersedeStatusRange($status, Contribution::attribute());

    expect($draft->responseStatuses())->toBe([$range]);
})->with([
    'informational' => ['1XX', '103'],
    'success' => ['2XX', '201'],
    'client error' => ['4XX', '404'],
    'server error' => ['5XX', '503'],
]);

it('keeps a range the declaration does not outrank', function (): void {
    // A range an author wrote at overlay level is their document; an attribute below it may add a
    // concrete status, but not retire what they published.
    $draft = new OperationDraft;
    $draft->response('3XX')->setDescription('Redirect', Contribution::overlay());

    $draft->supersedeStatusRange('302', Contribution::attribute());

    expect($draft->responseStatuses())->toContain('3XX');
});

it('reads only a concrete status as superseding a range', function (string $status): void {
    // Nothing but a three-digit status names a member of a class: a range supersedes no range, and a
    // key HTTP has no number for supersedes nothing at all.
    $draft = new OperationDraft;
    $draft->response('3XX')->setDescription('Redirect', Contribution::fallback());

    $draft->supersedeStatusRange($status, Contribution::attribute());

    expect($draft->responseStatuses())->toContain('3XX');
})->with([
    'the range itself' => ['3XX'],
    'the default response' => ['default'],
    'a bare class digit' => ['3'],
    'a four-digit number' => ['3021'],
    'nothing at all' => [''],
]);

it('is silent when there is no range to retract', function (): void {
    $draft = new OperationDraft;
    $draft->response('302')->setDescription('Found', Contribution::attribute());

    $draft->supersedeStatusRange('302', Contribution::attribute());

    expect($draft->responseStatuses())->toBe(['302']);
});

it('reports a response superseded only by a contribution that outranks every field on it', function (): void {
    // Field-level patching settles one value; this settles whether the node still says anything the
    // document needs, so ONE field written above the caller is enough to keep it.
    $response = new ResponseDraft('3XX');
    $response->setDescription('Redirect', Contribution::fallback());

    expect($response->isSupersededBy(Contribution::attribute()))->toBeTrue()
        ->and($response->isSupersededBy(Contribution::fallback()))->toBeFalse();

    $response->set('headers', ['Location' => []], Contribution::overlay());

    expect($response->isSupersededBy(Contribution::attribute()))->toBeFalse()
        ->and($response->isSupersededBy(Contribution::config()))->toBeTrue()
        ->and((new ResponseDraft('3XX'))->isSupersededBy(Contribution::fallback()))->toBeTrue();
});

it('reads the bodies a response owns, not only its own fields', function (): void {
    // Retiring the node moves its content too, so a keyword written above the caller — at any depth —
    // keeps the whole response, exactly as a field of its own would.
    $shallow = new ResponseDraft('3XX');
    $shallow->setDescription('Redirect', Contribution::fallback());
    $shallow->content('application/json')->set('type', 'object', Contribution::overlay());

    $nested = new ResponseDraft('3XX');
    $nested->setDescription('Redirect', Contribution::fallback());
    $nested->content('application/json')->set('type', 'object', Contribution::inference());
    $nested->content('application/json')->property('url')->set('type', 'string', Contribution::overlay());

    $inferred = new ResponseDraft('3XX');
    $inferred->content('application/json')->set('type', 'object', Contribution::inference());

    expect($shallow->isSupersededBy(Contribution::attribute()))->toBeFalse()
        ->and($nested->isSupersededBy(Contribution::attribute()))->toBeFalse()
        ->and($inferred->isSupersededBy(Contribution::attribute()))->toBeTrue();
});

it('retracts the media range a named media type supersedes', function (): void {
    $response = new ResponseDraft('200');
    $response->content('*/*')->set('type', 'string', Contribution::inference());

    $response->supersedeMediaRange('text/csv', Contribution::attribute());
    $response->content('text/csv')->set('type', 'string', Contribution::attribute());

    expect(array_keys($response->freeze()->content ?? []))->toBe(['text/csv']);
});

it('retracts only the ranges that cover the named media type', function (string $range, string $mediaType, bool $retracted): void {
    // A range covers a concrete type when its type half matches; a concrete key covers nothing but
    // itself, so one representation never retires another.
    $response = new ResponseDraft('200');
    $response->content($range)->set('type', 'string', Contribution::inference());

    $response->supersedeMediaRange($mediaType, Contribution::attribute());

    expect(array_key_exists($range, $response->freeze()->content ?? []))->toBe(! $retracted);
})->with([
    'any media type covers everything' => ['*/*', 'text/csv', true],
    'a type range covers its own type' => ['text/*', 'text/csv', true],
    'a type range covers nothing else' => ['text/*', 'application/json', false],
    'a concrete key is not a range' => ['application/json', 'text/csv', false],
    'a parameterised key is not a range' => ['application/json; charset=utf-8', 'application/json', false],
]);

it('declares nothing when the named media type is itself a range', function (): void {
    // Naming the range again is not naming a media type, so it supersedes nothing — including itself.
    $response = new ResponseDraft('200');
    $response->content('*/*')->set('type', 'string', Contribution::inference());

    $response->supersedeMediaRange('*/*', Contribution::attribute());

    expect(array_keys($response->freeze()->content ?? []))->toBe(['*/*']);
});

it('keeps a media range the declaration does not outrank, nested keywords included', function (): void {
    $overlaid = new ResponseDraft('200');
    $overlaid->content('*/*')->set('type', 'string', Contribution::overlay());
    $overlaid->supersedeMediaRange('text/csv', Contribution::attribute());

    $nested = new ResponseDraft('200');
    $nested->content('*/*')->set('type', 'object', Contribution::inference());
    $nested->content('*/*')->property('name')->set('type', 'string', Contribution::overlay());
    $nested->supersedeMediaRange('text/csv', Contribution::attribute());

    expect(array_keys($overlaid->freeze()->content ?? []))->toContain('*/*')
        ->and(array_keys($nested->freeze()->content ?? []))->toContain('*/*');
});

/*
 * The request body's own description. `requestBody` is one guarded field every producer writes whole,
 * so prose about how to fill it in rides ON the body rather than contesting it — a write at any layer
 * would have to replace the schema to carry a sentence, and a write at the layer that ALREADY assembled
 * the body would simply be shadowed.
 */
it('folds a declared body description into the body whoever wrote it left behind', function (Contribution $by): void {
    $draft = new OperationDraft;
    $draft->declareRequestBodyDescription('Send every field.');
    $draft->set('requestBody', [
        'required' => true,
        'content' => ['application/json' => ['schema' => ['type' => 'object']]],
    ], $by);

    $body = $draft->freeze()->rest['requestBody'];

    expect($body['description'])->toBe('Send every field.')
        ->and($body['required'])->toBeTrue()
        ->and($body['content']['application/json']['schema'])->toBe(['type' => 'object']);
})->with([
    // The layer that recovered a body, and the layer #[BodyParameter] assembles one at — which is the
    // same layer the declaration is read at, so a patch would tie and lose.
    'an integration-layer body' => [Contribution::integration('validation')],
    'an attribute-layer body' => [Contribution::attribute()],
    'an overlay-layer body' => [Contribution::overlay()],
]);

it('folds a body description in whichever order the body was written', function (): void {
    $before = new OperationDraft;
    $before->declareRequestBodyDescription('Send every field.');
    $before->set('requestBody', ['content' => []], Contribution::integration('validation'));

    $after = new OperationDraft;
    $after->set('requestBody', ['content' => []], Contribution::integration('validation'));
    $after->declareRequestBodyDescription('Send every field.');

    expect($before->freeze()->rest['requestBody'])->toBe($after->freeze()->rest['requestBody']);
});

it('keeps the first declared body description, so a later one cannot rewrite it', function (): void {
    $draft = new OperationDraft;
    $draft->set('requestBody', ['content' => []], Contribution::integration('validation'));
    $draft->declareRequestBodyDescription('The specific one.');
    $draft->declareRequestBodyDescription('The inherited one.');

    expect($draft->freeze()->rest['requestBody']['description'])->toBe('The specific one.');
});

it('never conjures a request body for a description to sit on', function (): void {
    $draft = new OperationDraft;
    $draft->declareRequestBodyDescription('Send every field.');

    expect($draft->freeze()->rest)->not->toHaveKey('requestBody');
});

it('leaves a request body it cannot make sense of as it found it', function (): void {
    $draft = new OperationDraft;
    $draft->set('requestBody', 'nonsense', Contribution::integration('validation'));
    $draft->declareRequestBodyDescription('Send every field.');

    expect($draft->freeze()->rest['requestBody'])->toBe('nonsense');
});
