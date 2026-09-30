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
use Docuccino\Core\Lint\DocumentLint;
use Docuccino\Core\Pipeline\Assembler;
use Docuccino\Core\Pipeline\AssemblyResult;
use Docuccino\Core\Pipeline\BuildWorkers;
use Docuccino\Core\Pipeline\OperationFragment;

/*
 * A run of lints goes to a worker of its own at its turn in the chain, and what it reports is gathered at the
 * end. The chain here has a lint before a transformer that writes and one after it, so each lint says what it
 * saw; the build with workers must report exactly what the build without them does, a lint that throws
 * included — and so must a build whose lints' worker never answers, which runs them itself at the end.
 */

beforeEach(function (): void {
    if (! BuildWorkers::forkable()) {
        $this->markTestSkipped('Forking needs the pcntl and posix extensions.');
    }
});

/** A lint that reports whether the draft carries the member the writer below adds. */
function sightingLint(string $name): DocumentLint
{
    return new class($name) implements DocumentLint
    {
        public function __construct(private readonly string $name) {}

        public function transform(UirDocumentDraft $document, DocumentContext $context): void
        {
            $context->report(new Diagnostic(Severity::Info, 'test.sighting', sprintf(
                '%s saw %s.',
                $this->name,
                $document->get('x-written') === null ? 'nothing written' : 'the written member',
            )));
        }
    };
}

/**
 * An assembly whose chain is a lint, a lint that throws, a transformer that writes and a lint again.
 *
 * @param  list<DocumentTransformer>  $extra  transformers after those four
 */
function assembleLints(BuildWorkers $workers, array $extra = []): AssemblyResult
{
    $writer = new class implements DocumentTransformer
    {
        public function transform(UirDocumentDraft $document, DocumentContext $context): void
        {
            $document->set('x-written', true);
        }
    };

    $breaking = new class implements DocumentLint
    {
        public function transform(UirDocumentDraft $document, DocumentContext $context): void
        {
            $context->report(new Diagnostic(Severity::Info, 'test.before-breaking', 'Reported before breaking.'));

            throw new RuntimeException('a lint that broke');
        }
    };

    return (new Assembler('docuccino', workers: $workers))->assemble(
        [new OperationFragment('/api/reports', 'get', (new OperationDraft)->freeze(), 'GET /api/reports')],
        new DocumentConfig('default', ['title' => 'T', 'version' => '1.0.0']),
        'doc:default',
        new ComponentRegistry,
        [],
        [sightingLint('first'), $breaking, $writer, sightingLint('second'), ...$extra],
        '1.0.0',
    );
}

/** @return list<array<string, mixed>> what an assembly reported, in an order of its own */
function assembledLintReports(AssemblyResult $result): array
{
    $diagnostics = array_map(static fn (Diagnostic $diagnostic): array => $diagnostic->toArray(), $result->diagnostics());
    usort($diagnostics, static fn (array $a, array $b): int => [$a['code'], $a['message']] <=> [$b['code'], $b['message']]);

    return $diagnostics;
}

/** Workers for at most `$limit`, counting in `$started` every one that is forked. */
function lintWorkers(int $limit, int &$started, ?Closure $inWorker = null): BuildWorkers
{
    return new BuildWorkers(static fn (): int => $limit, beforeFork: static function () use (&$started): void {
        $started++;
    }, inWorker: $inWorker);
}

it('reports from lints beside the build exactly what it reports running them itself', function (): void {
    $unused = 0;
    $alone = assembledLintReports(assembleLints(lintWorkers(1, $unused)));

    $started = 0;
    $workers = lintWorkers(4, $started);
    $beside = assembledLintReports(assembleLints($workers));

    $messages = array_column($alone, 'message', 'code');

    // Two runs, one each side of the writer, each read the draft as it stood at its own turn, and each worker's
    // answer is the one reported.
    expect($started)->toBe(2)
        ->and($workers->answered())->toBe(2)
        ->and(array_column(array_filter($alone, static fn (array $d): bool => $d['code'] === 'test.sighting'), 'message'))
        ->toBe(['first saw nothing written.', 'second saw the written member.'])
        ->and($messages)->toHaveKey('test.before-breaking')
        ->and($messages['document.transformer-failed'] ?? '')->toContain('a lint that broke')
        ->and($beside)->toBe($alone);
});

it('reports what the lints saw at their turn when their worker never answers', function (): void {
    // A worker killed before it answers — out of memory, say — leaves its lints to be run here when their
    // answer is asked for, which is after every transformer behind them has written.
    $unused = 0;
    $alone = assembledLintReports(assembleLints(lintWorkers(1, $unused)));

    $started = 0;
    $workers = lintWorkers(4, $started, static fn () => posix_kill(posix_getpid(), SIGKILL));
    $unanswered = assembledLintReports(assembleLints($workers));

    expect($started)->toBe(2)
        ->and($workers->answered())->toBe(0)
        ->and(array_column(array_filter($unanswered, static fn (array $d): bool => $d['code'] === 'test.sighting'), 'message'))
        ->toBe(['first saw nothing written.', 'second saw the written member.'])
        ->and($unanswered)->toBe($alone);
});

it('keeps what a lint writes out of the document, wherever the lint runs', function (): void {
    // Writing is outside what a lint may do ({@see DocumentLint}), and a copy of the draft is all it is given:
    // one that wrote would otherwise publish its write from one process and lose it from a worker.
    $writing = new class implements DocumentLint
    {
        public function transform(UirDocumentDraft $document, DocumentContext $context): void
        {
            $document->set('x-lint-wrote', true);
            $context->report(new Diagnostic(Severity::Info, 'test.wrote', 'Wrote to the draft.'));
        }
    };

    $unused = 0;
    $alone = assembleLints(lintWorkers(1, $unused), [$writing]);
    $started = 0;
    $beside = assembleLints(lintWorkers(4, $started), [$writing]);

    // It ran, both times, and what it wrote reached neither document.
    expect(array_column(assembledLintReports($alone), 'code'))->toContain('test.wrote')
        ->and(array_column(assembledLintReports($beside), 'code'))->toContain('test.wrote')
        ->and($alone->document)->not->toHaveKey('x-lint-wrote')
        ->and($alone->document)->toHaveKey('x-written')
        ->and($beside->document)->toBe($alone->document);
});
