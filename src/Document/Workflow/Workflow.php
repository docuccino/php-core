<?php

declare(strict_types=1);

namespace Docuccino\Core\Document\Workflow;

use Docuccino\Core\Support\Hydrate;

/**
 * One multi-step workflow (`x-docuccino.workflows[]`): a named sequence of operations, what it takes in,
 * and what it hands back.
 *
 * The steps are held in the order they run, settled where the workflow was assembled rather than here —
 * a sequence read out of a document is the sequence, and re-deriving one would be a second answer to a
 * question already settled.
 *
 * @internal
 */
final readonly class Workflow
{
    /**
     * @param  list<WorkflowStep>  $steps
     * @param  array<string, mixed>  $inputs  a JSON Schema object describing what the workflow takes
     * @param  array<string, string>  $outputs  name => the runtime expression that reads it out
     */
    public function __construct(
        public string $id,
        public array $steps,
        public ?string $summary = null,
        public ?string $description = null,
        public array $inputs = [],
        public array $outputs = [],
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
        );
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

        return $out;
    }
}
