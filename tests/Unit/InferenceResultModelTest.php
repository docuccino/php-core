<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Inference\ActionAnalysis;
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
        ->and($decoded->properties[1]->toArray())->toBe(['name' => 'note', 'type' => ScalarT::string()->toArray()]);
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
    $decoded = PropertyMetadata::fromArray(['name' => 'id', 'type' => 'nope', 'location' => 'nope']);

    expect($decoded->type)->toBeInstanceOf(UnknownT::class)
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
