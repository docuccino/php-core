<?php

declare(strict_types=1);

namespace Docuccino\Core\Document\Workflow;

use Docuccino\Core\Support\Hydrate;
use OutOfRangeException;

/**
 * One multi-step workflow: a named sequence of operations, what it takes in, and what it hands back. One
 * shape for both sources — a workflow an application declares (`x-docuccino.workflows[]`) and one an
 * Arazzo description states — so the two can be merged by `id`.
 *
 * The steps are held in the order they run, settled where the workflow was assembled rather than here —
 * a sequence read out of a document is the sequence, and re-deriving one would be a second answer to a
 * question already settled.
 */
final readonly class Workflow
{
    /**
     * @param  list<WorkflowStep>  $steps
     * @param  array<string, mixed>  $inputs  a JSON Schema object describing what the workflow takes
     * @param  array<string, string>  $outputs  name => the runtime expression that reads it out
     * @param  list<string>  $dependsOn  the workflows that must complete before this one, as Arazzo names them
     */
    public function __construct(
        public string $id,
        public array $steps,
        public ?string $summary = null,
        public ?string $description = null,
        public array $inputs = [],
        public array $outputs = [],
        public array $dependsOn = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Hydrate::stringOr($data['id'] ?? '', ''),
            steps: Hydrate::listOf($data['steps'] ?? null, WorkflowStep::fromArray(...)),
            summary: Hydrate::stringOrNull($data['summary'] ?? null),
            description: Hydrate::stringOrNull($data['description'] ?? null),
            inputs: Hydrate::map($data['inputs'] ?? null),
            outputs: Hydrate::stringMap($data['outputs'] ?? null),
            dependsOn: Hydrate::stringList($data['dependsOn'] ?? null),
        );
    }

    /**
     * Every outcome of the step at $index, success first: the ones it states, then — for a result where
     * none of them holds without a condition — the one Arazzo implies, marked `default`. Success goes on to
     * the next step, or ends the workflow after the last; failure ends it. A declared step states none, so
     * it gets exactly those two.
     *
     * @return list<StepOutcome>
     *
     * @throws OutOfRangeException when the workflow has no step at $index
     */
    public function outcomes(int $index): array
    {
        $step = $this->steps[$index] ?? throw new OutOfRangeException(sprintf('The workflow "%s" has no step %d.', $this->id, $index));
        $next = $this->steps[$index + 1] ?? null;

        $outcomes = [];
        foreach (StepResult::cases() as $on) {
            $otherwise = false;
            foreach ($step->outcomes as $outcome) {
                if ($outcome->on === $on) {
                    $outcomes[] = $outcome;
                    $otherwise = $otherwise || $outcome->criteria === [];
                }
            }

            if (! $otherwise) {
                $outcomes[] = $on === StepResult::Success && $next !== null
                    ? new StepOutcome($on, OutcomeAction::Goto, stepId: $next->id, default: true)
                    : new StepOutcome($on, OutcomeAction::End, default: true);
            }
        }

        return $outcomes;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = ['id' => $this->id];

        if ($this->summary !== null) {
            $out['summary'] = $this->summary;
        }

        if ($this->description !== null) {
            $out['description'] = $this->description;
        }

        if ($this->inputs !== []) {
            $out['inputs'] = $this->inputs;
        }

        $out['steps'] = array_map(static fn (WorkflowStep $step): array => $step->toArray(), $this->steps);

        if ($this->outputs !== []) {
            $out['outputs'] = $this->outputs;
        }

        if ($this->dependsOn !== []) {
            $out['dependsOn'] = $this->dependsOn;
        }

        return $out;
    }
}
