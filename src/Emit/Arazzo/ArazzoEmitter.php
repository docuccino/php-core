<?php

declare(strict_types=1);

namespace Docuccino\Core\Emit\Arazzo;

use Docuccino\Core\Canonical\Canonicalizer;
use Docuccino\Core\Canonical\CanonicalJsonSerializer;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Document\Workflow\StepParameter;
use Docuccino\Core\Document\Workflow\Workflow;
use Docuccino\Core\Document\Workflow\WorkflowStep;
use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\EmitReport;
use Docuccino\Core\Emit\EmitResult;
use Docuccino\Core\Emit\ReportingEmitter;
use Docuccino\Core\Emit\YamlSerializer;

/**
 * Emits the workflows a document declares as an Arazzo 1.1 description: one workflow per declaration,
 * one step per declared step, each addressing its operation by the `operationId` the OpenAPI artifact
 * publishes it under.
 *
 * **The translation this performs is identity → name.** A UIR step holds the operation's node id,
 * which is what keeps a workflow pointing at the right operation when a route is renamed; Arazzo
 * addresses operations by `operationId`, which is what an OpenAPI consumer has. Every step therefore
 * resolves through {@see OperationIndex}, and a step whose operation this document does not publish is
 * DROPPED with a diagnostic rather than emitted pointing at nothing — an Arazzo runner told to call an
 * operation its source description has not got fails at run time, with nothing to say why.
 *
 * **A document with no workflows emits no artifact.** Arazzo requires at least one workflow and at
 * least one source description, so there is no such thing as an empty Arazzo document: the honest
 * answer is to write nothing and say so, rather than a file that fails the specification it names.
 *
 * **Ordering comes from construction.** Like the Postman collection, this is not an OAS-shaped
 * document, and the {@see Canonicalizer} would re-key it into something no
 * Arazzo reader understands. Every member here is written in a fixed literal order, and the sequence a
 * workflow's steps are in is the sequence the author declared — re-sorting it would change what the
 * workflow does.
 *
 * @internal
 */
final readonly class ArazzoEmitter implements ReportingEmitter
{
    /** The specification version emitted. The published schema's own pattern is `^1\.1\.\d+(-.+)?$`. */
    private const string ARAZZO_VERSION = '1.1.0';

    /** What the emitted description calls the OpenAPI document its steps live in. */
    private const string SOURCE_NAME = 'openapi';

    public function __construct(
        private CanonicalJsonSerializer $serializer = new CanonicalJsonSerializer,
        private YamlSerializer $yaml = new YamlSerializer,
    ) {}

    public function format(): string
    {
        return 'arazzo';
    }

    public function emit(UirDocument $document, EmitOptions $options = new EmitOptions): string
    {
        return $this->emitWithReport($document, $options)->output;
    }

    public function emitWithReport(UirDocument $document, EmitOptions $options = new EmitOptions): EmitResult
    {
        /** @var list<Diagnostic> $diagnostics */
        $diagnostics = [];

        $description = $this->describe($document, $diagnostics, $options);

        if ($description === null) {
            return new EmitResult('', new EmitReport($diagnostics));
        }

        $output = $options->yaml
            ? $this->yaml->serialize($description)
            : $this->serializer->serialize($description);

        return new EmitResult($output, new EmitReport($diagnostics));
    }

    /**
     * The description, or null where this document has no workflows to publish.
     *
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>|null
     */
    private function describe(UirDocument $document, array &$diagnostics, EmitOptions $options): ?array
    {
        $declared = $document->docuccino?->workflows->workflows ?? [];

        $index = OperationIndex::of($document->toArray());

        $workflows = [];
        foreach ($declared as $workflow) {
            $emitted = $this->workflow($workflow, $index, $diagnostics);

            if ($emitted !== null) {
                $workflows[] = $emitted;
            }
        }

        if ($workflows === []) {
            $diagnostics[] = new Diagnostic(
                severity: Severity::Info,
                code: 'arazzo.no-workflows',
                message: $declared === []
                    ? 'This document declares no workflows, so no Arazzo description was written.'
                    : 'Every workflow this document declares lost all of its steps, so no Arazzo description was written.',
                help: 'An Arazzo description must carry at least one workflow, and a workflow at least one step, so there is no empty form of one to write.',
            );

            return null;
        }

        return [
            'arazzo' => self::ARAZZO_VERSION,
            'info' => array_filter([
                'title' => $document->info['title'] ?? 'API workflows',
                'summary' => $document->info['summary'] ?? null,
                'version' => $document->info['version'] ?? '0.0.0',
            ], static fn (mixed $value): bool => $value !== null),
            'sourceDescriptions' => [[
                'name' => self::SOURCE_NAME,
                'url' => $options->sourceUrl,
                'type' => 'openapi',
            ]],
            'workflows' => $workflows,
        ];
    }

    /**
     * One workflow, or null where none of its steps survived resolution — a workflow with no steps is
     * not a valid Arazzo workflow, and publishing the name of one with nothing in it tells a consumer
     * a sequence exists that they cannot run.
     *
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>|null
     */
    private function workflow(Workflow $workflow, OperationIndex $index, array &$diagnostics): ?array
    {
        if (! ArazzoNames::isId($workflow->id)) {
            $diagnostics[] = self::unusableName('workflow', $workflow->id, $workflow->id);

            return null;
        }

        $steps = [];
        foreach ($workflow->steps as $step) {
            $emitted = $this->step($step, $workflow, $index, $diagnostics);

            if ($emitted !== null) {
                $steps[] = $emitted;
            }
        }

        if ($steps === []) {
            return null;
        }

        $out = ['workflowId' => $workflow->id];

        if ($workflow->summary !== null) {
            $out['summary'] = $workflow->summary;
        }

        if ($workflow->description !== null) {
            $out['description'] = $workflow->description;
        }

        if ($workflow->inputs !== []) {
            $out['inputs'] = $workflow->inputs;
        }

        $out['steps'] = $steps;

        [$outputs, $refused] = ArazzoNames::usableOutputs($workflow->outputs);

        foreach ($refused as $name) {
            $diagnostics[] = self::unusableName('output', $name, $workflow->id);
        }

        if ($outputs !== []) {
            $out['outputs'] = $outputs;
        }

        return $out;
    }

    /**
     * One step, or null where its operation cannot be addressed.
     *
     * @param  list<Diagnostic>  $diagnostics
     * @return array<string, mixed>|null
     */
    private function step(WorkflowStep $step, Workflow $workflow, OperationIndex $index, array &$diagnostics): ?array
    {
        $operationId = $index->operationId($step->operation);

        if (! $index->knows($step->operation) || $operationId === null || $operationId === '') {
            $diagnostics[] = new Diagnostic(
                severity: Severity::Warning,
                code: 'arazzo.step-unresolved',
                message: sprintf(
                    'The step "%s" of the workflow "%s" names an operation this document %s, so the step was left out of the Arazzo description.',
                    $step->id,
                    $workflow->id,
                    $index->knows($step->operation) ? 'publishes under no operationId' : 'does not publish',
                ),
                help: 'An Arazzo step addresses its operation by operationId, so the operation has to be in the exported description and has to have one.',
            );

            return null;
        }

        if (! ArazzoNames::isId($step->id)) {
            $diagnostics[] = self::unusableName('step', $step->id, $workflow->id);

            return null;
        }

        $out = ['stepId' => $step->id];

        if ($step->description !== null) {
            $out['description'] = $step->description;
        }

        $out['operationId'] = $operationId;

        if ($step->parameters !== []) {
            $out['parameters'] = array_map(
                static fn (StepParameter $parameter): array => [
                    'name' => $parameter->name,
                    'in' => $parameter->in,
                    'value' => $parameter->value,
                ],
                $step->parameters,
            );
        }

        if ($step->body !== null) {
            $out['requestBody'] = [
                'contentType' => $step->body->contentType,
                'payload' => $step->body->payload,
            ];
        }

        // Derived rather than declared: the document already says what a successful call to this
        // operation answers with, and a step that checks nothing is a step a runner cannot fail.
        $success = $index->successStatus($step->operation);
        if ($success !== null) {
            $out['successCriteria'] = [['condition' => '$statusCode == '.$success]];
        }

        [$outputs, $refused] = ArazzoNames::usableOutputs($step->outputs);

        foreach ($refused as $name) {
            $diagnostics[] = self::unusableName('output', $name, $workflow->id);
        }

        if ($outputs !== []) {
            $out['outputs'] = $outputs;
        }

        return $out;
    }

    /**
     * A name Arazzo cannot carry, left out rather than published.
     *
     * Worth its own report because nothing else can make it: the published Arazzo schema types an id as
     * a plain string and accepts an unmatched `outputs` key silently, so the file would pass every
     * check we have and fail in the runner it was written for.
     */
    private static function unusableName(string $kind, string $name, string $workflow): Diagnostic
    {
        return new Diagnostic(
            severity: Severity::Warning,
            code: 'arazzo.name-unusable',
            message: sprintf(
                'The %s name "%s" in the workflow "%s" is not one Arazzo can carry, so it was left out of the description.',
                $kind,
                $name,
                $workflow,
            ),
            help: 'Arazzo takes letters, digits, `_` and `-` in a workflowId and a stepId, and those plus `.` in an output name.',
        );
    }
}
