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
 * @internal
 */
final readonly class WorkflowStep
{
    /**
     * @param  list<StepParameter>  $parameters
     * @param  array<string, string>  $outputs  name => the runtime expression that reads it out
     */
    public function __construct(
        public string $id,
        public string $operation,
        public ?string $description = null,
        public array $parameters = [],
        public ?StepBody $body = null,
        public array $outputs = [],
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
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = ['id' => $this->id, 'operation' => $this->operation];

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

        return $out;
    }
}
