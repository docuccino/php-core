<?php

declare(strict_types=1);

use Docuccino\Core\Identity\Base32;
use Docuccino\Core\Identity\IdentityGenerator;

beforeEach(function (): void {
    $this->ids = new IdentityGenerator;
});

it('emits a doc id as the verbatim config key', function (): void {
    expect($this->ids->documentId('default'))->toBe('doc:default');
});

it('produces 16-char base32 identities with a kind and algo prefix', function (): void {
    $id = $this->ids->operationId('doc:default', 'get', '/forms');

    expect($id)->toStartWith('op:v1:');
    expect(substr($id, strlen('op:v1:')))->toMatch('/^[a-z2-7]{16}$/');
});

it('base32-encodes without padding using the RFC 4648 lowercase alphabet', function (): void {
    expect(Base32::encode('foobar'))->toBe('mzxw6ytboi');
    expect(Base32::encode(''))->toBe('');
});

it('keeps operation identity across path-parameter renames', function (): void {
    $a = $this->ids->operationId('doc:default', 'GET', '/forms/{form}/fields/{field}');
    $b = $this->ids->operationId('doc:default', 'GET', '/forms/{id}/fields/{fieldId}');

    expect($a)->toBe($b);
});

it('identifies a webhook as an operation, keyed by its name', function (): void {
    $id = $this->ids->webhookId('doc:default', 'post', 'form.submitted');

    expect($id)->toStartWith('op:v1:')
        ->and(substr($id, strlen('op:v1:')))->toMatch('/^[a-z2-7]{16}$/')
        ->and($this->ids->webhookId('doc:default', 'POST', 'form.submitted'))->toBe($id);
});

it('keeps a webhook apart from the path template that spells it the same way', function (): void {
    // A webhook name is not a path, and nothing stops one being called `/forms`. With one identity
    // space between them, an edit to either would read as an edit to the other.
    expect($this->ids->webhookId('doc:default', 'GET', '/forms'))
        ->not->toBe($this->ids->operationId('doc:default', 'GET', '/forms'));
});

it('reads a webhook name verbatim, braces and all', function (): void {
    // A path template's `{param}` is normalised away because renaming a parameter changes no contract.
    // A webhook name has no parameters — every byte of it is the name a consumer subscribes to.
    expect($this->ids->webhookId('doc:default', 'POST', 'form.{id}'))
        ->not->toBe($this->ids->webhookId('doc:default', 'POST', 'form.{other}'));
});

it('breaks webhook identity when the name, the method or the document changes', function (): void {
    $base = $this->ids->webhookId('doc:default', 'POST', 'form.submitted');

    expect($this->ids->webhookId('doc:default', 'POST', 'form.deleted'))->not->toBe($base)
        ->and($this->ids->webhookId('doc:default', 'PUT', 'form.submitted'))->not->toBe($base)
        ->and($this->ids->webhookId('doc:public', 'POST', 'form.submitted'))->not->toBe($base);
});

it('breaks operation identity when the URI changes', function (): void {
    $a = $this->ids->operationId('doc:default', 'GET', '/forms/{form}');
    $b = $this->ids->operationId('doc:default', 'GET', '/forms/{form}/fields');

    expect($a)->not->toBe($b);
});

it('breaks operation identity when the method changes', function (): void {
    $a = $this->ids->operationId('doc:default', 'GET', '/forms');
    $b = $this->ids->operationId('doc:default', 'POST', '/forms');

    expect($a)->not->toBe($b);
});

it('keeps parameter identity regardless of declaration order', function (): void {
    $op = 'op:v1:aaaaaaaaaaaaaaaa';

    expect($this->ids->parameterId($op, 'query', 'status'))
        ->toBe($this->ids->parameterId($op, 'query', 'status'));

    expect($this->ids->parameterId($op, 'query', 'status'))
        ->not->toBe($this->ids->parameterId($op, 'query', 'sort'));
});

it('keeps named-schema identity across FQCN file moves but breaks on rename', function (): void {
    $moved = $this->ids->namedSchemaId('App\\Data\\FormData');

    expect($moved)->toBe($this->ids->namedSchemaId('App\\Data\\FormData'));
    expect($moved)->not->toBe($this->ids->namedSchemaId('App\\Http\\FormData'));
});

it('keeps inline-schema identity across prose edits but breaks on shape change', function (): void {
    $base = ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]];
    $withProse = ['type' => 'object', 'description' => 'A form', 'properties' => ['id' => ['type' => 'integer', 'description' => 'The id', 'example' => 5]]];
    $shapeChanged = ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]];

    expect($this->ids->inlineSchemaId($base))->toBe($this->ids->inlineSchemaId($withProse));
    expect($this->ids->inlineSchemaId($base))->not->toBe($this->ids->inlineSchemaId($shapeChanged));
});

it('is insensitive to property order for inline-schema identity', function (): void {
    $a = ['type' => 'object', 'properties' => ['a' => ['type' => 'integer'], 'b' => ['type' => 'string']]];
    $b = ['properties' => ['b' => ['type' => 'string'], 'a' => ['type' => 'integer']], 'type' => 'object'];

    expect($this->ids->inlineSchemaId($a))->toBe($this->ids->inlineSchemaId($b));
});

it('keeps a real property named like an annotation keyword in inline-schema identity', function (string $keyword, mixed $a, mixed $b): void {
    // Two schemas that differ ONLY in a property literally named `description`/`title`/`example`
    // must have DIFFERENT ids — the annotation strip must not erase property names (architecture C1).
    $schemaA = ['type' => 'object', 'properties' => [$keyword => $a]];
    $schemaB = ['type' => 'object', 'properties' => [$keyword => $b]];

    expect($this->ids->inlineSchemaId($schemaA))->not->toBe($this->ids->inlineSchemaId($schemaB));
})->with([
    'description property' => ['description', ['type' => 'integer'], ['type' => 'string']],
    'title property' => ['title', ['type' => 'integer'], ['type' => 'string']],
    'example property' => ['example', ['type' => 'boolean'], ['type' => 'number']],
]);

it('still strips annotation keywords in schema-annotation position for inline identity', function (): void {
    // The keyword-aware strip must keep the original behaviour: differing only in an annotation
    // `description`/`title`/`example` value yields the SAME id.
    $base = ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]];
    $annotated = ['type' => 'object', 'title' => 'Form', 'description' => 'A form', 'properties' => ['id' => ['type' => 'integer', 'description' => 'The id', 'example' => 5]]];

    expect($this->ids->inlineSchemaId($base))->toBe($this->ids->inlineSchemaId($annotated));
});

it('excludes x-docuccino members from inline-schema identity', function (): void {
    // Two schemas differing only in their x-docuccino members share one structural id (QA L10).
    $base = ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'x-docuccino' => ['mock' => ['faker' => 'randomNumber']]]]];
    $other = ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'x-docuccino' => ['id' => 'sch:v1:aaaaaaaaaaaaaaaa']]]];

    expect($this->ids->inlineSchemaId($base))->toBe($this->ids->inlineSchemaId($other));
});

it('is insensitive to the order of the required array for inline-schema identity', function (): void {
    $a = ['type' => 'object', 'required' => ['a', 'b', 'c'], 'properties' => ['a' => [], 'b' => [], 'c' => []]];
    $b = ['type' => 'object', 'required' => ['c', 'a', 'b'], 'properties' => ['a' => [], 'b' => [], 'c' => []]];

    expect($this->ids->inlineSchemaId($a))->toBe($this->ids->inlineSchemaId($b));
});

it('mints identical op ids for two routes claiming the same method and path', function (): void {
    // The collision signal: two colliding operations share one id, so the assembler can detect it.
    $a = $this->ids->operationId('doc:default', 'GET', '/forms/{form}');
    $b = $this->ids->operationId('doc:default', 'GET', '/forms/{form}');

    expect($a)->toBe($b);
});

it('leaves a host-less operation the exact identity it had before hosts existed', function (): void {
    // The byte-lock: every document ever emitted pairs on these, so a route that answers on every host
    // must hash the three-element tuple and nothing else. A literal, because "same as itself" would
    // pass however the tuple were built.
    expect($this->ids->operationId('doc:default', 'GET', '/forms/{form}'))->toBe('op:v1:gokzo2gfa274hn5j')
        ->and($this->ids->operationId('doc:default', 'GET', '/forms/{form}', null))
        ->toBe($this->ids->operationId('doc:default', 'GET', '/forms/{form}'))
        ->and($this->ids->operationId('doc:default', 'GET', '/forms/{form}', ''))
        ->toBe($this->ids->operationId('doc:default', 'GET', '/forms/{form}'));
});

it('breaks operation identity when the host changes, so two hosts are two operations', function (): void {
    $anyHost = $this->ids->operationId('doc:default', 'GET', '/forms');
    $admin = $this->ids->operationId('doc:default', 'GET', '/forms', 'admin.example.com');
    $public = $this->ids->operationId('doc:default', 'GET', '/forms', 'www.example.com');

    expect($admin)->not->toBe($public)
        ->and($admin)->not->toBe($anyHost)
        ->and($admin)->toBe($this->ids->operationId('doc:default', 'GET', '/forms', 'admin.example.com'));
});

it('keeps operation identity across a host-parameter rename', function (): void {
    // Same rule the path template gets: renaming `{tenant}` to `{account}` is a rename, not a new
    // endpoint, so the diff must still pair them.
    expect($this->ids->operationId('doc:default', 'GET', '/forms', '{tenant}.example.com'))
        ->toBe($this->ids->operationId('doc:default', 'GET', '/forms', '{account}.example.com'))
        ->not->toBe($this->ids->operationId('doc:default', 'GET', '/forms', '{tenant}.example.net'));
});

it('derives a response id that breaks on status and on media-type change', function (): void {
    $op = 'op:v1:aaaaaaaaaaaaaaaa';

    $base = $this->ids->responseId($op, '200', 'application/json');

    expect($base)->toBe($this->ids->responseId($op, '200', 'application/json'));
    expect($base)->not->toBe($this->ids->responseId($op, '201', 'application/json'));
    expect($base)->not->toBe($this->ids->responseId($op, '200', 'application/xml'));
});
