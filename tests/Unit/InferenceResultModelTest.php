<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\CallableRef;
use Docuccino\Core\Inference\CallCondition;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\ComponentDeclaration;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\Frame;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Inference\TypeCondition;

/**
 * What an engine hands back is a SERIALIZABLE model: every result crosses a process boundary as JSON
 * (the out-of-process engine harness) and every hop has to come back the same value. So the contract
 * these tests pin is round-trip identity plus total degradation — a malformed payload yields a
 * well-formed object carrying `UnknownT`, never an exception, because the pipeline downstream has
 * nothing to catch with.
 */
it('round-trips an action analysis, sorting and deduping its dependency files', function (): void {
    $analysis = new ActionAnalysis(
        returns: [new ReturnSite(ScalarT::string(), new SourceLocation('/app/Http/Controllers/X.php', 12, 340))],
        throws: [new ThrownException(
            exceptionFqcn: 'Illuminate\\Auth\\Access\\AuthorizationException',
            httpStatusHint: 403,
            callChain: [new Frame('App\\Services\\Orders::reserve', new SourceLocation('/app/Services/Orders.php', 44))],
            confidence: ThrowConfidence::Certain,
            disposition: ThrowDisposition::Signal,
        )],
        diagnostics: [new Diagnostic(Severity::Warning, 'inference.action-failed', 'nope')],
        dependencyFiles: ['/app/b.php', '/app/a.php', '/app/b.php'],
    );
    $payload = $analysis->toArray();

    expect($payload['dependencyFiles'])->toBe(['/app/a.php', '/app/b.php'])
        ->and(ActionAnalysis::fromArray($payload)->toArray())->toBe($payload);

    $decoded = ActionAnalysis::fromArray($payload);
    expect($decoded->returns[0]->location->line)->toBe(12)
        ->and($decoded->returns[0]->location->pos)->toBe(340)
        ->and($decoded->throws[0]->httpStatusHint)->toBe(403)
        ->and($decoded->throws[0]->callChain[0]->symbol)->toBe('App\\Services\\Orders::reserve')
        ->and($decoded->throws[0]->confidence)->toBe(ThrowConfidence::Certain)
        ->and($decoded->diagnostics[0]->code)->toBe('inference.action-failed');
});

it('serializes byte-identically for the same analysis, whichever order the files arrived in', function (): void {
    $shuffled = new ActionAnalysis(dependencyFiles: ['/app/b.php', '/app/a.php']);
    $ordered = new ActionAnalysis(dependencyFiles: ['/app/a.php', '/app/b.php']);

    expect(json_encode($shuffled->toArray()))->toBe(json_encode($ordered->toArray()));
});

it('degrades a malformed action analysis to an empty one rather than throwing', function (): void {
    $decoded = ActionAnalysis::fromArray([
        'returns' => 'not a list',
        'throws' => null,
        'diagnostics' => 7,
        'dependencyFiles' => ['/app/a.php', 42, null],
    ]);

    expect($decoded->returns)->toBe([])
        ->and($decoded->throws)->toBe([])
        ->and($decoded->diagnostics)->toBe([])
        // The one string survives; the junk beside it is dropped, not coerced.
        ->and($decoded->dependencyFiles)->toBe(['/app/a.php']);
});

it('round-trips class metadata with its properties, summary and dependency files', function (): void {
    $metadata = new ClassMetadata(
        fqcn: 'App\\Data\\Invoice',
        properties: [
            new PropertyMetadata('id', ScalarT::int(), 'The invoice id.', '17', new SourceLocation('/app/Data/Invoice.php', 9)),
            new PropertyMetadata('note', ScalarT::string()),
            new PropertyMetadata('memo', ScalarT::string(), initialised: false),
        ],
        summary: 'An invoice.',
        dependencyFiles: ['/app/Data/Invoice.php', '/app/Data/Invoice.php'],
    );

    $payload = $metadata->toArray();
    $decoded = ClassMetadata::fromArray($payload);

    expect($payload['dependencyFiles'])->toBe(['/app/Data/Invoice.php'])
        ->and($decoded->toArray())->toBe($payload)
        ->and($decoded->summary)->toBe('An invoice.')
        ->and($decoded->properties[0]->example)->toBe('17')
        ->and($decoded->properties[0]->location?->line)->toBe(9)
        // The optional members stay ABSENT rather than serializing as nulls, so two runs of the same
        // class produce the same bytes.
        ->and($decoded->properties[1]->toArray())->toBe(['name' => 'note', 'type' => ScalarT::string()->toArray()])
        // `false` is an answer — a constructor path completes without the property — and not an absence.
        ->and($decoded->properties[2]->initialised)->toBeFalse()
        ->and($decoded->properties[1]->initialised)->toBeNull();
});

it('omits an empty summary and dependency set from class metadata entirely', function (): void {
    expect((new ClassMetadata('App\\Data\\Bare'))->toArray())
        ->toBe(['fqcn' => 'App\\Data\\Bare', 'properties' => []]);
});

it('degrades malformed class metadata around the properties it can still read', function (): void {
    $decoded = ClassMetadata::fromArray([
        'fqcn' => ['not', 'a', 'string'],
        'properties' => [['name' => 5, 'type' => 'nope'], ['name' => 'ok', 'type' => ScalarT::string()->toArray()]],
        'summary' => 5,
        'dependencyFiles' => 'not a list',
    ]);

    expect($decoded->fqcn)->toBe('')
        ->and($decoded->summary)->toBeNull()
        ->and($decoded->dependencyFiles)->toBe([])
        ->and($decoded->properties)->toHaveCount(2)
        ->and($decoded->properties[0]->name)->toBe('')
        ->and($decoded->properties[0]->type)->toBeInstanceOf(UnknownT::class)
        ->and($decoded->properties[1]->name)->toBe('ok');
});

it('degrades a malformed property type and location to an unknown type and no location', function (): void {
    $decoded = PropertyMetadata::fromArray(['name' => 'id', 'type' => 'nope', 'location' => 'nope', 'initialised' => 'yes']);

    expect($decoded->type)->toBeInstanceOf(UnknownT::class)
        ->and($decoded->initialised)->toBeNull()
        ->and($decoded->location)->toBeNull()
        ->and($decoded->summary)->toBeNull();
});

it('degrades a malformed return site to an unknown type at an unknown location', function (): void {
    $decoded = ReturnSite::fromArray(['type' => 'nope', 'location' => 'nope']);

    expect($decoded->type)->toBeInstanceOf(UnknownT::class)
        ->and($decoded->location->file)->toBe('')
        ->and($decoded->location->line)->toBeNull()
        ->and($decoded->component)->toBeNull();
});

it('round-trips the component a return path declared, and omits the key when none did', function (): void {
    // The name a render method declares reaches the adapter on the return site and has to survive the
    // fragment cache with the rest of the analysis — a warm build publishing a different name from a cold
    // one is the trap `x-docuccino.facts.component` exists to close.
    $declared = new ReturnSite(
        ScalarT::string(),
        new SourceLocation('/app/Exceptions/Renderer.php', 30),
        new ComponentDeclaration('PortalRejection', 'App\\Exceptions\\Renderer::renderRejection', new SourceLocation('/app/Exceptions/Renderer.php', 44)),
    );
    $payload = $declared->toArray();

    expect(ReturnSite::fromArray($payload)->toArray())->toBe($payload)
        ->and(ReturnSite::fromArray($payload)->component?->symbol)->toBe('App\\Exceptions\\Renderer::renderRejection')
        ->and(ReturnSite::fromArray($payload)->component?->location->line)->toBe(44)
        // A return path nothing named states nothing, rather than stating a null.
        ->and((new ReturnSite(ScalarT::string(), new SourceLocation('')))->toArray())->not->toHaveKey('component');
});

it('degrades a malformed component declaration to an empty one rather than throwing', function (): void {
    $decoded = ComponentDeclaration::fromArray(['name' => 12, 'symbol' => ['nope'], 'location' => 'nope']);

    // A scalar coerces, as everywhere else in the model; anything that is not one leaves the member empty.
    expect($decoded->name)->toBe('12')
        ->and($decoded->symbol)->toBe('')
        ->and($decoded->location->file)->toBe('')
        ->and(ComponentDeclaration::fromArray([])->name)->toBe('');
});

it('re-homes a return site onto a declaration made further out on the call path', function (): void {
    $site = new ReturnSite(ScalarT::string(), new SourceLocation('/app/x.php', 3));
    $outer = new ComponentDeclaration('PortalProblem', 'App\\Exceptions\\Renderer::__invoke');

    expect($site->withComponent($outer)->component)->toBe($outer)
        ->and($site->withComponent($outer)->type)->toBe($site->type)
        ->and($site->withComponent(null)->component)->toBeNull();
});

it('degrades a malformed thrown exception to a signal of unknown status', function (): void {
    $decoded = ThrownException::fromArray([
        'exceptionFqcn' => 12,
        'httpStatusHint' => '403',
        'callChain' => ['not an array'],
        'confidence' => 'invented',
        'disposition' => 9,
    ]);

    expect($decoded->exceptionFqcn)->toBe('')
        ->and($decoded->httpStatusHint)->toBeNull()
        ->and($decoded->callChain[0]->symbol)->toBe('')
        ->and($decoded->confidence)->toBe(ThrowConfidence::Likely)
        ->and($decoded->disposition)->toBe(ThrowDisposition::Signal);
});

it('degrades a malformed frame to an empty symbol at an unknown location', function (): void {
    $decoded = Frame::fromArray(['symbol' => 3, 'location' => 'nope']);

    expect($decoded->symbol)->toBe('')
        ->and($decoded->location->file)->toBe('');
});

it('keys a thrown exception on (fqcn, status), so two statuses never dedupe into one', function (): void {
    $forbidden = new ThrownException('App\\X', 403, [], ThrowConfidence::Certain, ThrowDisposition::Signal);
    $missing = new ThrownException('App\\X', 404, [], ThrowConfidence::Certain, ThrowDisposition::Signal);
    $unknown = new ThrownException('App\\X', null, [], ThrowConfidence::Certain, ThrowDisposition::Signal);

    expect($forbidden->identityKey())->toBe('App\\X@403')
        ->and($missing->identityKey())->not->toBe($forbidden->identityKey())
        ->and($unknown->identityKey())->toBe('App\\X@null');
});

it('ranks every throw confidence, most certain first', function (ThrowConfidence $confidence, int $rank): void {
    expect($confidence->rank())->toBe($rank);
})->with([
    'certain' => [ThrowConfidence::Certain, 3],
    'declared' => [ThrowConfidence::Declared, 2],
    'likely' => [ThrowConfidence::Likely, 1],
]);

it('normalises a negative line to no line at all', function (): void {
    // PHPStan reports -1 for synthesised throw points and execution-end nodes.
    $location = new SourceLocation('/app/a.php', -1);

    expect($location->line)->toBeNull()
        ->and($location->toArray())->toBe(['file' => '/app/a.php'])
        ->and(SourceLocation::fromArray(['file' => '/app/a.php', 'line' => 'nope', 'pos' => 'nope'])->line)->toBeNull();
});

it('round-trips a source location carrying a byte offset', function (): void {
    $payload = (new SourceLocation('/app/a.php', 4, 91))->toArray();

    expect($payload)->toBe(['file' => '/app/a.php', 'line' => 4, 'pos' => 91])
        ->and(SourceLocation::fromArray($payload)->toArray())->toBe($payload)
        ->and(SourceLocation::fromArray(['file' => 9])->file)->toBe('');
});

it('round-trips the parameter a return hands back and the calls proven at it, and omits both where there are none', function (): void {
    $site = new ReturnSite(
        ScalarT::string(),
        new SourceLocation('/app/x.php', 3),
        returnsParameter: 'response',
        conditions: [
            new CallCondition('request', 'is', ['api/*', 'hooks/*'], false),
            new CallCondition('response', 'getStatusCode', [], 419),
        ],
        typeConditions: [
            new TypeCondition('response', 'Illuminate\\Http\\JsonResponse', false),
            new TypeCondition('e', 'RuntimeException', true),
        ],
    );
    $payload = $site->toArray();

    expect(ReturnSite::fromArray($payload)->toArray())->toBe($payload)
        ->and(ReturnSite::fromArray($payload)->conditions[1]->value)->toBe(419)
        ->and(ReturnSite::fromArray($payload)->typeConditions)->toEqual($site->typeConditions)
        // Absent keys, not empty ones, so an analysis carrying neither serializes as it always did.
        ->and((new ReturnSite(ScalarT::string(), new SourceLocation('')))->toArray())->toBe(['type' => ScalarT::string()->toArray(), 'location' => (new SourceLocation(''))->toArray()]);
});

it('carries both facts onto a declaration made further out on the call path', function (): void {
    $site = new ReturnSite(ScalarT::string(), new SourceLocation('/app/x.php', 3), returnsParameter: 'response', conditions: [new CallCondition('request', 'is', ['api/*'], true)], typeConditions: [new TypeCondition('response', 'Illuminate\\Http\\JsonResponse', true)]);
    $moved = $site->withComponent(new ComponentDeclaration('Problem', 'App\\Renderer::render'));

    expect($moved->returnsParameter)->toBe('response')
        ->and($moved->conditions)->toBe($site->conditions)
        ->and($moved->typeConditions)->toBe($site->typeConditions);
});

it('degrades a malformed call condition around the members it can still read', function (): void {
    // A scalar coerces, as everywhere else in the model; anything that is not one leaves the member empty.
    $decoded = CallCondition::fromArray(['parameter' => 1, 'method' => [], 'arguments' => ['api/*', 3], 'value' => ['x']]);

    expect($decoded->toArray())->toBe(['parameter' => '1', 'method' => '', 'arguments' => ['api/*'], 'value' => false]);
});

it('degrades a malformed type condition around the members it can still read', function (): void {
    // Only a real `true` holds: a truthy string is not a proof the parameter is an instance.
    $decoded = TypeCondition::fromArray(['parameter' => 1, 'class' => [], 'value' => 'yes']);

    expect($decoded->toArray())->toBe(['parameter' => '1', 'class' => '', 'value' => false]);
});

it('keys a callable analysed for every reachable return apart from one analysed for the first', function (): void {
    $first = new CallableRef('/app/bootstrap.php', null, null, 12, 'e', 'App\\Exceptions\\Missing');
    $every = new CallableRef('/app/bootstrap.php', null, null, 12, 'e', 'App\\Exceptions\\Missing', narrowToEvery: true);

    expect($every->symbol())->not->toBe($first->symbol())
        ->and($every->target())->toBe($first->target());
});

it('keys a callable narrowing a property of $this apart from one narrowing a parameter to the same class', function (): void {
    // A resource collection's with() is read once per envelope, so the narrowed subject is part of the key.
    $parameter = new CallableRef('/app/Http/Resources/Listed.php', 'App\\Listed', 'with', 0, 'resource', 'Illuminate\\Support\\Collection', narrowToEvery: true);
    $property = new CallableRef('/app/Http/Resources/Listed.php', 'App\\Listed', 'with', 0, narrowType: 'Illuminate\\Support\\Collection', narrowToEvery: true, narrowProperty: 'resource');
    $page = new CallableRef('/app/Http/Resources/Listed.php', 'App\\Listed', 'with', 0, narrowType: 'Illuminate\\Pagination\\LengthAwarePaginator', narrowToEvery: true, narrowProperty: 'resource');

    expect($property->symbol())->toBe('App\\Listed::with#$this->resource Illuminate\\Support\\Collection#every')
        ->and($property->symbol())->not->toBe($parameter->symbol())
        ->and($property->symbol())->not->toBe($page->symbol())
        ->and($property->target())->toBe($parameter->target());
});

it('refuses a callable narrowing a parameter and a property of $this at once', function (): void {
    // Each would be the subject on its own; together nothing says which the narrowing is of.
    new CallableRef('/app/Http/Resources/Listed.php', 'App\\Listed', 'with', 0, 'request', 'Illuminate\\Support\\Collection', narrowProperty: 'resource');
})->throws(InvalidArgumentException::class, 'App\\Listed::with narrows either a parameter or a property of $this, not both.');

it('names the subject a callable narrows as source spells it', function (?string $parameter, ?string $property, ?string $subject): void {
    $ref = new CallableRef('/app/Listed.php', 'App\\Listed', 'with', 0, $parameter, 'App\\Thing', narrowProperty: $property);

    expect($ref->narrowedSubject())->toBe($subject);
})->with([
    'a parameter' => ['e', null, '$e'],
    'a property of $this' => [null, 'resource', '$this->resource'],
    'nothing' => [null, null, null],
]);
