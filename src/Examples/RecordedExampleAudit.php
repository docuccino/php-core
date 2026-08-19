<?php

declare(strict_types=1);

namespace Docuccino\Core\Examples;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\BuiltIn\SharedErrorResponses;
use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Contracts\DocumentTransformer;
use Docuccino\Core\Extensions\Document\UirDocumentDraft;
use Docuccino\Core\Lint\LintOperation;

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
 * @phpstan-type Documented array<string, array<string, true>>
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
        $store = RecordingStore::for($context->config, $this->basePath);

        if ($store === null) {
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
                    $context->config->recordingsDir() ?? $store->directory,
                ),
                help: 'Record some by registering Docuccino\\Laravel\\Testing\\ApiContract::record() in your test bootstrap and running the suite, or drop examples.recordings from the document config.',
            ));

            return;
        }

        $documented = self::documented($document->toArray());
        $hoists = RepresentationPolicy::fromConfig($context->config->representation)->errorComponents;

        foreach ($files as $file) {
            $this->check($store, $file, $documented, $hoists, $context);
        }
    }

    /**
     * @param  Documented  $documented
     */
    private function check(RecordingStore $store, string $file, array $documented, bool $hoists, DocumentContext $context): void
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

        $this->reportUnpublishableNames($recording, $file, $documented[$operationId], $hoists, $context);

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
                help: 'Re-record it — the recorder replaces credentials on the way out. If the value is genuinely public, list the pointer this message names under lint.leakage.allow; a bare property name silences the lint but never the redaction.',
            ));
        }
    }

    /**
     * The names a recording asked for that the document cannot carry.
     *
     * A media type's `examples` map is not stripped before the shared-error hoist groups bodies, so a
     * named example on a status it groups would take that response out of the component an unrelated
     * route also points at. The body still publishes, as the singular `example`; the name does not,
     * and an author who named a scenario is owed the news.
     *
     * @param  array<string, true>  $statuses  the statuses this operation documents
     */
    private function reportUnpublishableNames(ExampleRecording $recording, string $file, array $statuses, bool $hoists, DocumentContext $context): void
    {
        if (! $hoists) {
            return;
        }

        $dropped = [];
        foreach ($recording->responses as $response) {
            if ($response->isNamed() && isset($statuses[$response->status]) && SharedErrorResponses::shares($response->status)) {
                $dropped[$response->slot()][] = $response->name;
            }
        }

        foreach ($dropped as $slot => $names) {
            $context->report(new Diagnostic(
                severity: Severity::Warning,
                code: 'examples.recording-name-unpublished',
                message: sprintf(
                    'The %s recordings in %s publish without their names (%s), which an error response cannot carry.',
                    $slot,
                    $file,
                    implode(', ', $names),
                ),
                help: 'Named examples on an error status would keep that response out of the shared error component other routes point at. Record it without a name — the body still publishes as the example — or set representation.errors.components to false for this document.',
            ));
        }
    }

    /**
     * Every operation id the document publishes, and the statuses each of them documents.
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

            $responses = $operation->operation['responses'] ?? null;
            $statuses = is_array($responses) ? array_map(strval(...), array_keys($responses)) : [];

            $ids[$id] = array_fill_keys($statuses, true);
        }

        return $ids;
    }
}
