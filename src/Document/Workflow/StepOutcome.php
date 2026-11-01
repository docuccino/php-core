<?php

declare(strict_types=1);

namespace Docuccino\Core\Document\Workflow;

use Docuccino\Core\Support\Hydrate;

/**
 * What happens after a step, as Arazzo's success and failure actions say: on a result, when every one of
 * its criteria holds (none: otherwise), go to a step or a workflow, end, or retry after so many seconds
 * up to so many times. `name` is the action's own name ("end as not shortlisted").
 *
 * `default` marks an outcome nobody wrote: the one Arazzo gives a step whose source says nothing for that
 * result ({@see Workflow::outcomes()}).
 */
final readonly class StepOutcome
{
    /**
     * @param  list<Criterion>  $criteria  all must hold; none means otherwise
     */
    public function __construct(
        public StepResult $on,
        public OutcomeAction $action,
        public ?string $name = null,
        public ?string $stepId = null,
        public ?string $workflowId = null,
        public int|float|null $retryAfter = null,
        public ?int $retryLimit = null,
        public array $criteria = [],
        public bool $default = false,
    ) {}

    /**
     * Null where the result or the action is not one this model knows, which no valid document writes.
     *
     * @param  array<string, mixed>  $data  this class's own {@see toArray()}
     */
    public static function fromArray(array $data): ?self
    {
        $on = StepResult::tryFrom(Hydrate::stringOr($data['on'] ?? '', ''));
        $action = OutcomeAction::tryFrom(Hydrate::stringOr($data['action'] ?? '', ''));

        if ($on === null || $action === null) {
            return null;
        }

        $retryAfter = $data['retryAfter'] ?? null;

        return new self(
            on: $on,
            action: $action,
            name: Hydrate::stringOrNull($data['name'] ?? null),
            stepId: Hydrate::stringOrNull($data['stepId'] ?? null),
            workflowId: Hydrate::stringOrNull($data['workflowId'] ?? null),
            retryAfter: is_int($retryAfter) || is_float($retryAfter) ? $retryAfter : null,
            retryLimit: Hydrate::intOrNull($data['retryLimit'] ?? null),
            criteria: Hydrate::listOf($data['criteria'] ?? null, Criterion::fromArray(...)),
            default: Hydrate::boolOrNull($data['default'] ?? null) === true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = array_filter([
            'on' => $this->on->value,
            'action' => $this->action->value,
            'name' => $this->name,
            'stepId' => $this->stepId,
            'workflowId' => $this->workflowId,
            'retryAfter' => $this->retryAfter,
            'retryLimit' => $this->retryLimit,
        ], static fn (mixed $value): bool => $value !== null);

        if ($this->criteria !== []) {
            $out['criteria'] = array_map(static fn (Criterion $criterion): array => $criterion->toArray(), $this->criteria);
        }

        if ($this->default) {
            $out['default'] = true;
        }

        return $out;
    }
}
