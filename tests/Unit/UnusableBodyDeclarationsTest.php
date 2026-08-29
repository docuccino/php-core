<?php

declare(strict_types=1);

use Docuccino\Attributes\BodyParameter;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\AttributeSet;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Context\RouteDescriptor;
use Docuccino\Core\Extensions\Document\UirDocumentDraft;
use Docuccino\Core\Extensions\ResolvedExtensions;
use Docuccino\Core\Extensions\Schema\SchemaClassAttributes;
use Docuccino\Core\Extensions\Schema\UnusableBodyDeclarations;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Tests\Fixtures\HonouredDeclarationsNode;
use Docuccino\Core\Tests\Fixtures\MisreadDeclarationsNode;

/**
 * The third answer a declaration on a request type can get: read on a type, and still reaching nothing
 * because no operation built from the type documents a request body. It is document-wide by
 * construction — a type bound to a read route AND a write one is doing its job — so the route records
 * an OBSERVATION and the verdict is reached once, here.
 */
function unusableObservation(string $method, string $action = 'App\\Things'): RouteContext
{
    return new RouteContext(
        route: new RouteDescriptor([$method], 'api/things'),
        actionRef: new ActionRef('', $action, 'handle'),
        attributes: new AttributeSet,
        engine: new NullTypeEngine,
        document: new DocumentConfig('default', []),
        extensions: new ResolvedExtensions(typeToSchema: DefaultTypeMappers::all()),
    );
}

/** Drain a route's notes into a collector the way the pipeline does — the one path into the aggregate. */
function unusableCollect(UnusableBodyDeclarations $log, RouteContext ...$contexts): void
{
    foreach ($contexts as $context) {
        foreach ($context->notes()->all()[UnusableBodyDeclarations::CHANNEL] ?? [] as $key => $values) {
            $log->collect($key, $values);
        }
    }
}

/** @return list<Diagnostic> */
function unusableReport(UnusableBodyDeclarations $log): array
{
    $context = new DocumentContext(new DocumentConfig('default', []), 'doc');
    $log->transform(new UirDocumentDraft([]), $context);

    return $context->diagnostics->sorted();
}

it('records nothing for a type that declares no #[BodyParameter]', function (): void {
    $read = unusableObservation('GET');
    UnusableBodyDeclarations::observe($read, MisreadDeclarationsNode::class, false);

    // Twenty-one other class-target attributes on that fixture, and none of them is this one's business.
    expect($read->notes()->all())->toBe([]);
});

it('reports a type observed unusable and never used', function (): void {
    $read = unusableObservation('GET');
    UnusableBodyDeclarations::observe($read, HonouredDeclarationsNode::class, false);

    $log = new UnusableBodyDeclarations;
    unusableCollect($log, $read);

    $reported = unusableReport($log);

    expect($reported)->toHaveCount(1)
        ->and($reported[0]->code)->toBe('attribute.schema-class-unusable')
        ->and($reported[0]->severity)->toBe(Severity::Warning)
        ->and($reported[0]->message)->toBe(
            'The #[BodyParameter] on '.HonouredDeclarationsNode::class
            .' is read only where the route documents a request body, and no operation this document '
            .'builds from the type does; it was ignored.',
        )
        ->and($reported[0]->help)->toContain('#[QueryParameter]');
});

/**
 * The whole reason the verdict is document-wide. One DTO answers `GET /things` and `POST /things`; its
 * declaration is load-bearing on the POST, and a per-route report from the read-verb arm would tell the
 * author their correct declaration does nothing — a diagnostic firing where nothing can be done. Delete
 * the `used` half of the reconciliation and this is the test that fails.
 */
it('says nothing about a type bound to a read route AND a write route', function (): void {
    $read = unusableObservation('GET');
    $write = unusableObservation('POST');
    UnusableBodyDeclarations::observe($read, HonouredDeclarationsNode::class, false);
    UnusableBodyDeclarations::observe($write, HonouredDeclarationsNode::class, true);

    $log = new UnusableBodyDeclarations;
    unusableCollect($log, $read, $write);

    expect($log->unusable())->toBe([])
        ->and(unusableReport($log))->toBe([]);
});

it('says nothing about a type only ever used as a body', function (): void {
    $write = unusableObservation('POST');
    UnusableBodyDeclarations::observe($write, HonouredDeclarationsNode::class, true);

    $log = new UnusableBodyDeclarations;
    unusableCollect($log, $write);

    expect(unusableReport($log))->toBe([]);
});

/**
 * The verdict may not be a function of the order the routes were met. Feeding the same two observations
 * in both orders is the executed form of that: a reconciliation that answered "first one wins" passes
 * one of these rows and fails the other.
 */
it('reaches the same verdict whichever route was built first', function (bool $readFirst): void {
    $read = unusableObservation('GET');
    $write = unusableObservation('POST');
    UnusableBodyDeclarations::observe($read, HonouredDeclarationsNode::class, false);
    UnusableBodyDeclarations::observe($write, HonouredDeclarationsNode::class, true);

    $log = new UnusableBodyDeclarations;
    unusableCollect($log, ...($readFirst ? [$read, $write] : [$write, $read]));

    expect($log->unusable())->toBe([]);
})->with(['read route first' => [true], 'write route first' => [false]]);

/**
 * And the ORDER it reports in is the classes', not the routes'. Collected back-to-front, so a reporter
 * that published its aggregate as built would fail here.
 */
it('reports in class-name order however the routes were met', function (): void {
    $log = new UnusableBodyDeclarations;
    $log->collect('App\\Zulu', [UnusableBodyDeclarations::UNUSABLE]);
    $log->collect('App\\Alpha', [UnusableBodyDeclarations::UNUSABLE]);

    expect($log->unusable())->toBe(['App\\Alpha', 'App\\Zulu']);
});

it('forgets one document’s observations before the next', function (): void {
    $log = new UnusableBodyDeclarations;
    $log->collect(HonouredDeclarationsNode::class, [UnusableBodyDeclarations::UNUSABLE]);
    $log->forget();

    expect($log->unusable())->toBe([])
        ->and($log->channel())->toBe(UnusableBodyDeclarations::CHANNEL);
});

it('folds repeats across routes rather than reporting one per route', function (): void {
    $log = new UnusableBodyDeclarations;
    $log->collect(HonouredDeclarationsNode::class, [UnusableBodyDeclarations::UNUSABLE]);
    $log->collect(HonouredDeclarationsNode::class, [UnusableBodyDeclarations::UNUSABLE]);

    expect(unusableReport($log))->toHaveCount(1);
});

/**
 * The guard over the third state. `CONDITIONAL` is the table that says an honoured attribute is only
 * read at some routes; this class is the only thing that reports one, and it reports exactly one
 * attribute. A second row added there with no observation site of its own would otherwise be published
 * under this one's wording, which says something false about it.
 */
it('reconciles every conditionally-read attribute the tables name', function (): void {
    expect(array_keys(SchemaClassAttributes::CONDITIONAL))->toBe([UnusableBodyDeclarations::ATTRIBUTE])
        ->and(UnusableBodyDeclarations::ATTRIBUTE)->toBe(BodyParameter::class)
        // Conditional is a qualification of honoured, not a fourth state beside it.
        ->and(array_diff_key(SchemaClassAttributes::CONDITIONAL, SchemaClassAttributes::HONOURED))->toBe([])
        // …and an attribute is never in both a "read here" and a "read elsewhere" table.
        ->and(array_intersect_key(SchemaClassAttributes::CONDITIONAL, SchemaClassAttributes::ELSEWHERE))->toBe([]);
});

it('says nothing about a class it cannot load', function (): void {
    $read = unusableObservation('GET');
    UnusableBodyDeclarations::observe($read, 'App\\Nowhere\\Missing', false);

    expect($read->notes()->all())->toBe([]);
});
