<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\DocumentConfig;
use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Contracts\DocumentTransformer;
use Docuccino\Core\Extensions\Document\UirDocumentDraft;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Pipeline\Assembler;
use Docuccino\Core\Pipeline\AssemblyResult;
use Docuccino\Core\Pipeline\OperationFragment;

/**
 * The transformer chain is a public extension point, and a lint is a transformer that contributes
 * nothing to the document — so an exception out of one used to cost the author their whole export and
 * hand them a stack trace instead. The chain reports and carries on; the boundary is here rather than
 * only inside the lint that showed it, because every transformer, ours and everybody else's, reaches
 * the document through this one loop.
 *
 * @param  list<DocumentTransformer>  $transformers
 */
function assembleWithTransformers(array $transformers): AssemblyResult
{
    return (new Assembler('docuccino'))->assemble(
        [new OperationFragment('/api/reports', 'get', (new OperationDraft)->freeze(), 'GET /api/reports')],
        new DocumentConfig('default', ['title' => 'T', 'version' => '1.0.0']),
        'doc:default',
        new ComponentRegistry,
        [],
        $transformers,
        '1.0.0',
    );
}

/** A transformer that writes a title, then throws with `$message`. */
function throwingTransformer(string $message): DocumentTransformer
{
    return new class($message) implements DocumentTransformer
    {
        public function __construct(private readonly string $message) {}

        public function transform(UirDocumentDraft $document, DocumentContext $context): void
        {
            throw new RuntimeException($this->message);
        }
    };
}

/** A transformer that reports one diagnostic and returns. */
function reportingTransformer(string $code): DocumentTransformer
{
    return new class($code) implements DocumentTransformer
    {
        public function __construct(private readonly string $code) {}

        public function transform(UirDocumentDraft $document, DocumentContext $context): void
        {
            $context->report(new Diagnostic(severity: Severity::Info, code: $this->code, message: 'Ran.'));
        }
    };
}

it('turns a throwing transformer into an error diagnostic and still assembles the document', function (): void {
    $result = assembleWithTransformers([throwingTransformer('Everything is on fire')]);

    $failed = array_values(array_filter(
        $result->diagnostics,
        static fn (Diagnostic $d): bool => $d->code === 'document.transformer-failed',
    ));

    expect($failed)->toHaveCount(1)
        ->and($failed[0]->severity)->toBe(Severity::Error)
        ->and($failed[0]->message)->toContain('Everything is on fire')
        // Naming the transformer is the point: the reader's next move is finding out whose it is.
        ->and($failed[0]->message)->toContain('DocumentTransformer@anonymous')
        ->and($failed[0]->help)->toContain('report it')
        // An anonymous class carries its own DECLARING FILE inside `::class` — absolute, behind a NUL
        // byte, with a per-process counter after the line number. None of the three may reach a
        // published diagnostic: the path is relative to the package root (there being no application
        // base path here), and the counter, which two runs need not agree on, is gone.
        ->and($failed[0]->message)->not->toContain("\0")
        ->and($failed[0]->message)->toContain('declared in tests/Unit/AssemblerTransformerGuardTest.php:')
        ->and($failed[0]->message)->not->toContain(dirname(__DIR__, 4))
        ->and($failed[0]->message)->toMatch('/AssemblerTransformerGuardTest\.php:\d+ threw/')
        // And the document is there, which is the whole reason for catching at all.
        ->and($result->document['paths'])->toHaveKey('/api/reports');
});

it('runs the transformers after the one that threw', function (): void {
    $result = assembleWithTransformers([
        reportingTransformer('demo.before'),
        throwingTransformer('Everything is on fire'),
        reportingTransformer('demo.after'),
    ]);

    expect(array_column($result->diagnostics, 'code'))
        ->toContain('demo.before', 'document.transformer-failed', 'demo.after');
});

it('publishes no machine path in the message a failed transformer leaves behind', function (): void {
    // Diagnostics are embedded in the document, so a path from the build machine would make two
    // machines emit different bytes for the same code. Composed around the relativised message,
    // never scrubbed out of the finished one — the transformer's own class name survives whole.
    $outside = '/'.uniqid('docuccino-elsewhere-', true).'/vendor/acme/src/Reader.php';

    $result = assembleWithTransformers([throwingTransformer(
        sprintf('file_get_contents(%s): Failed to open stream', $outside),
    )]);

    $failed = array_values(array_filter(
        $result->diagnostics,
        static fn (Diagnostic $d): bool => $d->code === 'document.transformer-failed',
    ));

    expect($failed)->toHaveCount(1)
        ->and($failed[0]->message)->toContain('Failed to open stream')
        ->and($failed[0]->message)->toContain('Reader.php')
        ->and($failed[0]->message)->not->toContain($outside);
});

it('names an exception that says nothing by its class', function (): void {
    $result = assembleWithTransformers([throwingTransformer('')]);

    $failed = array_values(array_filter(
        $result->diagnostics,
        static fn (Diagnostic $d): bool => $d->code === 'document.transformer-failed',
    ));

    expect($failed)->toHaveCount(1)
        ->and($failed[0]->message)->toContain(RuntimeException::class);
});
