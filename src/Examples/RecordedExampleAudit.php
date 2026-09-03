<?php

declare(strict_types=1);

namespace Docuccino\Core\Examples;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Contracts\DocumentTransformer;
use Docuccino\Core\Extensions\Document\UirDocumentDraft;
use Docuccino\Core\Lint\LintOperation;
use Docuccino\Core\Support\ConfinedPath;
use Docuccino\Core\Support\PlainText;

/**
 * Says what is wrong with the committed recordings, once per document.
 *
 * Every recording diagnostic is raised here rather than beside the extension that publishes one, for
 * two reasons. A transformer sees the whole document, which is the only place a recording nobody
 * claimed can be told from one that is simply for another operation; and a transformer runs on every
 * build, warm or cold, so what a warm build reports is what a cold one reports without any of it
 * having to ride a cached fragment.
 *
 * Diagnostics only — it never touches the document. The publishing half applies the same safety rule
 * silently, so nothing here is a report about something that already shipped.
 *
 * @phpstan-type Documented array<string, true>
 *
 * @internal
 */
final readonly class RecordedExampleAudit implements DocumentTransformer
{
    public function __construct(
        private string $basePath,
        private ExampleRedaction $redaction = new ExampleRedaction,
    ) {}

    public function transform(UirDocumentDraft $document, DocumentContext $context): void
    {
        $configured = $context->config->recordingsDir();
        $store = RecordingStore::for($context->config, $this->basePath);

        if ($store === null) {
            // A directory the document named and {@see RecordingStore::for()} answered nothing for was
            // REFUSED, which is the one thing this silence must not read as: an author who typed a path
            // that leaves the application would otherwise get a document with no recorded examples and no
            // reason why. A path holding a NUL byte never reaches here — `recordingsDir()` answers null for
            // it and the adapter reports it against the key that held it — so this has one cause and says it.
            if ($configured !== null) {
                $context->report(new Diagnostic(
                    severity: Severity::Warning,
                    code: 'examples.recordings-escapes-base',
                    message: sprintf(
                        'examples.recordings "%s" does not name a path inside the application and was rejected, so the document publishes no recorded examples.',
                        PlainText::of($configured),
                    ),
                    help: ConfinedPath::CONFIG_FILE_ESCAPED_HELP,
                ));
            }

            return;
        }

        $files = $store->fileNames();

        if ($files === []) {
            $context->report(new Diagnostic(
                severity: Severity::Info,
                code: 'examples.recordings-empty',
                // The configured spelling, not the resolved one: a diagnostic naming a build machine's
                // layout reads differently on every checkout and points at nothing the reader wrote.
                message: sprintf(
                    'No response recordings were found in %s, so the document publishes none.',
                    $configured ?? $store->directory,
                ),
                help: 'Recording is opt-in per assertion: name the scenario a test set up — assertValidResponse(recordAs: \'empty-cart\') — and run your suite with the recorder registered. An assertion that names nothing checks the response and records none of it. Or drop examples.recordings from the document config.',
            ));

            return;
        }

        $documented = self::documented($document->toArray());

        foreach ($files as $file) {
            $this->check($store, $file, $documented, $context);
        }
    }

    /**
     * @param  Documented  $documented
     */
    private function check(RecordingStore $store, string $file, array $documented, DocumentContext $context): void
    {
        $path = $store->directory.'/'.$file;
        $recording = RecordingStore::at($path);

        if ($recording === null) {
            $context->report(new Diagnostic(
                severity: Severity::Warning,
                code: 'examples.recording-unreadable',
                message: sprintf('%s is not a response recording Docuccino can read, so nothing from it is published.', $file),
                help: 'Re-record it by running your suite with the recorder registered, or delete the file.',
            ));

            return;
        }

        $operationId = RecordingStore::operationIdFor($file);

        if ($operationId !== $recording->operationId) {
            $context->report(new Diagnostic(
                severity: Severity::Warning,
                code: 'examples.recording-unreadable',
                message: sprintf('%s records operation %s, which is not the operation its filename names.', $file, $recording->operationId),
                help: 'Delete the file and re-record it — a recording is filed under the id it holds.',
            ));

            return;
        }

        if (! isset($documented[$operationId])) {
            $context->report(new Diagnostic(
                severity: Severity::Warning,
                code: 'examples.recording-orphaned',
                message: sprintf(
                    'The recording in %s is for an operation this document no longer has (%s%s).',
                    $file,
                    $operationId,
                    $recording->endpoint === '' ? '' : ', recorded from '.$recording->endpoint,
                ),
                help: 'The route was renamed, moved or removed. Delete the file, then re-record the operation that replaced it.',
            ));

            return;
        }

        self::reportUnnamed($recording, $file, $context);

        foreach ($recording->responses as $response) {
            $findings = $this->redaction->findings($response->body);

            if ($findings === []) {
                continue;
            }

            $context->report(new Diagnostic(
                severity: Severity::Warning,
                code: 'examples.recording-unsafe',
                message: sprintf(
                    'The %s %s recording in %s still holds what looks like a credential at %s; it is not published.',
                    $response->status,
                    $response->mediaType,
                    $file,
                    implode(', ', $findings),
                ),
                help: 'Re-record it — the recorder replaces a credential STRING on the way out. A number it reports and leaves alone, because a placeholder where the schema says integer would make the example contradict its own contract: stop returning it, or — if the value is genuinely public — list the pointer this message names under lint.leakage.allow. A bare property name silences the lint but never the redaction.',
            ));
        }
    }

    /**
     * A committed body no assertion named.
     *
     * Recording is asked for one exchange at a time, and the name is the asking, so a file holding an
     * unnamed body was written by a suite from before that was true. Such a body still publishes — the
     * reader takes it as the singular `example`, and dropping it would take an example out of a document
     * on an upgrade nobody asked to change anything — but no run can ever refresh it, which is the fact
     * an author would otherwise learn from a body that quietly stopped tracking their application.
     *
     * Once per file rather than once per body: the remedy is the same sentence for all of them, and a
     * file is what the author opens.
     */
    private static function reportUnnamed(ExampleRecording $recording, string $file, DocumentContext $context): void
    {
        $slots = [];
        foreach ($recording->responses as $response) {
            if (! $response->isNamed()) {
                $slots[$response->slot()] = true;
            }
        }

        if ($slots === []) {
            return;
        }

        $context->report(new Diagnostic(
            severity: Severity::Info,
            code: 'examples.recording-unnamed',
            message: sprintf(
                'The recordings in %s (%s) named no scenario, so they still publish and no run can re-record them.',
                $file,
                implode(', ', array_keys($slots)),
            ),
            help: 'Name the scenario at the assertion that produces the body — assertValidResponse(recordAs: \'empty-cart\') — and the next run replaces it with the named one. Delete the file instead if the endpoint no longer needs an example.',
        ));
    }

    /**
     * Every operation id the document publishes, which is what tells a recording nobody claimed from one
     * that is simply another operation's.
     *
     * @param  array<string, mixed>  $document
     * @return Documented
     */
    private static function documented(array $document): array
    {
        $ids = [];

        foreach (LintOperation::all($document) as $operation) {
            $extension = $operation->operation['x-docuccino'] ?? null;
            $id = is_array($extension) ? ($extension['id'] ?? null) : null;

            if (! is_string($id) || $id === '') {
                continue;
            }

            $ids[$id] = true;
        }

        return $ids;
    }
}
