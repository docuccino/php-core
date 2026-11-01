<?php

declare(strict_types=1);

namespace Docuccino\Core\Document\Workflow;

use Docuccino\Core\Support\Hydrate;

/**
 * One step of a workflow: the operation it calls, what it passes, and what a later step may read back
 * out of the response.
 *
 * **`operation` is the operation's own node id, never its `operationId`.** An operationId is a NAME,
 * minted from the route and re-minted whenever the route changes; a workflow keyed on one silently
 * points at nothing the day somebody renames a route. The node id is a function of the operation
 * itself, which is what makes a workflow survive the refactors a hand-written Arazzo file does not —
 * and what lets a diff say that a step's operation changed rather than merely that a string did.
 * Emitters resolve it back to whatever name their own format addresses operations by.
 *
 * A step read from an Arazzo description has no node id: it names its target as Arazzo does, by
 * `operationId`, `operationPath`, `workflowId` or (1.1) `channelPath`, and `operation` is `''`. Its
 * `successCriteria` and `outcomes` are the ones it states; a declared step states none, and
 * {@see Workflow::outcomes()} gives either one the outcomes Arazzo implies.
 */
final readonly class WorkflowStep
{
    /**
     * @param  list<StepParameter>  $parameters
     * @param  array<string, string>  $outputs  name => the runtime expression that reads it out
     * @param  list<Criterion>  $successCriteria  every one must hold for the step to have succeeded
     * @param  list<StepOutcome>  $outcomes  as written: success first, then failure, each in its order
     */
    public function __construct(
        public string $id,
        public string $operation,
        public ?string $description = null,
        public array $parameters = [],
        public ?StepBody $body = null,
        public array $outputs = [],
        public ?string $operationId = null,
        public ?string $operationPath = null,
        public ?string $workflowId = null,
        public ?string $channelPath = null,
        public array $successCriteria = [],
        public array $outcomes = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Hydrate::stringOr($data['id'] ?? '', ''),
            operation: Hydrate::stringOr($data['operation'] ?? '', ''),
            description: Hydrate::stringOrNull($data['description'] ?? null),
            parameters: Hydrate::listOf($data['parameters'] ?? null, StepParameter::fromArray(...)),
            body: Hydrate::objectOrNull($data['body'] ?? null, StepBody::fromArray(...)),
            outputs: Hydrate::stringMap($data['outputs'] ?? null),
            operationId: Hydrate::stringOrNull($data['operationId'] ?? null),
            operationPath: Hydrate::stringOrNull($data['operationPath'] ?? null),
            workflowId: Hydrate::stringOrNull($data['workflowId'] ?? null),
            channelPath: Hydrate::stringOrNull($data['channelPath'] ?? null),
            successCriteria: Hydrate::listOf($data['successCriteria'] ?? null, Criterion::fromArray(...)),
            outcomes: array_values(array_filter(Hydrate::listOf($data['outcomes'] ?? null, StepOutcome::fromArray(...)))),
        );
    }

    /**
     * The members past `outputs` are written only where they are set, so a declared step writes what the
     * UIR defines and nothing more.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = ['id' => $this->id, 'operation' => $this->operation];

        foreach (['operationId' => $this->operationId, 'operationPath' => $this->operationPath, 'workflowId' => $this->workflowId, 'channelPath' => $this->channelPath] as $member => $value) {
            if ($value !== null) {
                $out[$member] = $value;
            }
        }

        if ($this->description !== null) {
            $out['description'] = $this->description;
        }

        if ($this->parameters !== []) {
            $out['parameters'] = array_map(
                static fn (StepParameter $parameter): array => $parameter->toArray(),
                $this->parameters,
            );
        }

        if ($this->body !== null) {
            $out['body'] = $this->body->toArray();
        }

        if ($this->outputs !== []) {
            $out['outputs'] = $this->outputs;
        }

        if ($this->successCriteria !== []) {
            $out['successCriteria'] = array_map(static fn (Criterion $criterion): array => $criterion->toArray(), $this->successCriteria);
        }

        if ($this->outcomes !== []) {
            $out['outcomes'] = array_map(static fn (StepOutcome $outcome): array => $outcome->toArray(), $this->outcomes);
        }

        return $out;
    }
}
