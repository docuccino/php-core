<?php

declare(strict_types=1);

namespace Docuccino\Core\Reader\Arazzo;

use Docuccino\Core\Document\Workflow\Criterion;
use Docuccino\Core\Document\Workflow\OutcomeAction;
use Docuccino\Core\Document\Workflow\StepBody;
use Docuccino\Core\Document\Workflow\StepOutcome;
use Docuccino\Core\Document\Workflow\StepParameter;
use Docuccino\Core\Document\Workflow\StepResult;
use Docuccino\Core\Document\Workflow\Workflow;
use Docuccino\Core\Document\Workflow\WorkflowStep;
use Docuccino\Core\SpecValidation\Finding;
use Docuccino\Core\Support\Hydrate;
use Docuccino\Core\Support\JsonPointer;

/**
 * An Arazzo description, read as an array, into {@see ArazzoDescription}: one pass that also notes every
 * `$components` reference that resolves to nothing, as a finding at its `reference` member.
 *
 * Nothing is refused: a member of the wrong shape reads as absent, since the schema is what reports it.
 *
 * @internal
 */
final class DescriptionBuilder
{
    /** A reusable object's runtime expression: the component kind, then its name (which may hold dots). */
    private const string REFERENCE = '/^\$components\.(parameters|successActions|failureActions)\.(.+)$/';

    /** The `$ref` a workflow's inputs names a reusable input by. */
    private const string INPUT_REF = '#/components/inputs/';

    /** @var array<string, mixed> */
    private array $components;

    /** @var list<Finding> */
    private array $unresolved = [];

    /**
     * @param  array<string, mixed>  $document
     */
    public function __construct(private readonly array $document)
    {
        $this->components = Hydrate::mapOrNull($document['components'] ?? null) ?? [];
    }

    public function description(): ArazzoDescription
    {
        $this->unresolved = [];
        $info = Hydrate::mapOrNull($this->document['info'] ?? null) ?? [];

        $sources = [];
        foreach (self::items($this->document['sourceDescriptions'] ?? null) as $source) {
            $source = Hydrate::mapOrNull($source) ?? [];
            $sources[] = new SourceDescription(
                Hydrate::stringOr($source['name'] ?? '', ''),
                Hydrate::stringOr($source['url'] ?? '', ''),
                SourceType::tryFrom(Hydrate::stringOr($source['type'] ?? '', '')),
            );
        }

        $workflows = [];
        foreach (self::items($this->document['workflows'] ?? null) as $index => $workflow) {
            $workflows[] = $this->workflow(Hydrate::mapOrNull($workflow) ?? [], '/workflows/'.$index);
        }

        return new ArazzoDescription(
            sourceDescriptions: $sources,
            workflows: $workflows,
            components: $this->components,
            title: Hydrate::stringOrNull($info['title'] ?? null),
            summary: Hydrate::stringOrNull($info['summary'] ?? null),
            description: Hydrate::stringOrNull($info['description'] ?? null),
            version: Hydrate::stringOrNull($info['version'] ?? null),
        );
    }

    /**
     * Every `$components` reference that resolves to nothing, in the order written.
     *
     * @return list<Finding>
     */
    public function unresolved(): array
    {
        $this->description();

        return $this->unresolved;
    }

    /**
     * @param  array<string, mixed>  $workflow
     */
    private function workflow(array $workflow, string $at): Workflow
    {
        $parameters = $this->parameters($workflow['parameters'] ?? null, $at.'/parameters');
        $success = $this->actions($workflow['successActions'] ?? null, StepResult::Success, $at.'/successActions');
        $failure = $this->actions($workflow['failureActions'] ?? null, StepResult::Failure, $at.'/failureActions');

        $steps = [];
        foreach (self::items($workflow['steps'] ?? null) as $index => $step) {
            $steps[] = $this->step(Hydrate::mapOrNull($step) ?? [], $at.'/steps/'.$index, $parameters, [...$success, ...$failure]);
        }

        return new Workflow(
            id: Hydrate::stringOr($workflow['workflowId'] ?? '', ''),
            steps: $steps,
            summary: Hydrate::stringOrNull($workflow['summary'] ?? null),
            description: Hydrate::stringOrNull($workflow['description'] ?? null),
            inputs: $this->inputs($workflow['inputs'] ?? null),
            outputs: Hydrate::stringMap($workflow['outputs'] ?? null),
            dependsOn: Hydrate::stringList($workflow['dependsOn'] ?? null),
        );
    }

    /**
     * @param  array<string, mixed>  $step
     * @param  list<StepParameter>  $shared  the workflow's, for every step that does not override one
     * @param  list<StepOutcome>  $actions  the workflow's, likewise
     */
    private function step(array $step, string $at, array $shared, array $actions): WorkflowStep
    {
        $parameters = $this->parameters($step['parameters'] ?? null, $at.'/parameters');
        $written = array_map(static fn (StepParameter $parameter): string => $parameter->in.' '.$parameter->name, $parameters);
        foreach ($shared as $parameter) {
            if (! in_array($parameter->in.' '.$parameter->name, $written, true)) {
                $parameters[] = $parameter;
            }
        }

        $outcomes = [];
        foreach (StepResult::cases() as $on) {
            $own = $this->actions($step[$on === StepResult::Success ? 'onSuccess' : 'onFailure'] ?? null, $on, $at.($on === StepResult::Success ? '/onSuccess' : '/onFailure'));
            $named = array_map(static fn (StepOutcome $outcome): ?string => $outcome->name, $own);
            $inherited = array_filter($actions, static fn (StepOutcome $outcome): bool => $outcome->on === $on && ! in_array($outcome->name, $named, true));

            $outcomes = [...$outcomes, ...$own, ...array_values($inherited)];
        }

        $body = Hydrate::mapOrNull($step['requestBody'] ?? null);

        return new WorkflowStep(
            id: Hydrate::stringOr($step['stepId'] ?? '', ''),
            operation: '',
            description: Hydrate::stringOrNull($step['description'] ?? null),
            parameters: $parameters,
            body: $body === null ? null : new StepBody(
                Hydrate::stringOr($body['contentType'] ?? '', ''),
                $body['payload'] ?? null,
                Hydrate::listOfMaps($body['replacements'] ?? null) ?? [],
            ),
            outputs: Hydrate::stringMap($step['outputs'] ?? null),
            operationId: Hydrate::stringOrNull($step['operationId'] ?? null),
            operationPath: Hydrate::stringOrNull($step['operationPath'] ?? null),
            workflowId: Hydrate::stringOrNull($step['workflowId'] ?? null),
            channelPath: Hydrate::stringOrNull($step['channelPath'] ?? null),
            successCriteria: $this->criteria($step['successCriteria'] ?? null),
            outcomes: $outcomes,
        );
    }

    /**
     * @return list<StepParameter>
     */
    private function parameters(mixed $list, string $at): array
    {
        $parameters = [];
        foreach (self::items($list) as $index => $item) {
            $parameter = $this->resolved(Hydrate::mapOrNull($item) ?? [], 'parameters', JsonPointer::child($at, (string) $index));
            if ($parameter !== null) {
                $parameters[] = new StepParameter(
                    Hydrate::stringOr($parameter['name'] ?? '', ''),
                    Hydrate::stringOr($parameter['in'] ?? '', ''),
                    $parameter['value'] ?? null,
                );
            }
        }

        return $parameters;
    }

    /**
     * @return list<StepOutcome>
     */
    private function actions(mixed $list, StepResult $on, string $at): array
    {
        $outcomes = [];
        foreach (self::items($list) as $index => $item) {
            $action = $this->resolved(Hydrate::mapOrNull($item) ?? [], $on === StepResult::Success ? 'successActions' : 'failureActions', JsonPointer::child($at, (string) $index));
            $type = $action === null ? null : OutcomeAction::tryFrom(Hydrate::stringOr($action['type'] ?? '', ''));
            if ($action === null || $type === null) {
                continue;
            }

            $retryAfter = $action['retryAfter'] ?? null;
            $outcomes[] = new StepOutcome(
                on: $on,
                action: $type,
                name: Hydrate::stringOrNull($action['name'] ?? null),
                stepId: Hydrate::stringOrNull($action['stepId'] ?? null),
                workflowId: Hydrate::stringOrNull($action['workflowId'] ?? null),
                retryAfter: is_int($retryAfter) || is_float($retryAfter) ? $retryAfter : null,
                retryLimit: Hydrate::intOrNull($action['retryLimit'] ?? null),
                criteria: $this->criteria($action['criteria'] ?? null),
            );
        }

        return $outcomes;
    }

    /**
     * @return list<Criterion>
     */
    private function criteria(mixed $list): array
    {
        return array_map(
            static fn (mixed $criterion): Criterion => Criterion::fromArray(Hydrate::mapOrNull($criterion) ?? []),
            self::items($list),
        );
    }

    /**
     * $item, or the component its `reference` names — with a parameter's `value` set over the component's —
     * or null, noted, where the reference names no component of $kind.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private function resolved(array $item, string $kind, string $at): ?array
    {
        if (! array_key_exists('reference', $item)) {
            return $item;
        }

        $reference = Hydrate::stringOr($item['reference'], '');
        $component = preg_match(self::REFERENCE, $reference, $match) === 1 && $match[1] === $kind
            ? Hydrate::mapOrNull((Hydrate::mapOrNull($this->components[$kind] ?? null) ?? [])[$match[2]] ?? null)
            : null;

        if ($component === null) {
            $this->unresolved[] = new Finding(
                $at.'/reference',
                'reference',
                sprintf('"%s" names nothing in the description\'s components.%s', $reference, $kind),
            );

            return null;
        }

        if ($kind === 'parameters' && array_key_exists('value', $item)) {
            $component['value'] = $item['value'];
        }

        return $component;
    }

    /**
     * A workflow's inputs: a JSON Schema, or the reusable one its `$ref` names (as written where it names
     * none — `inputs` is a schema, and a `$ref` in one the schema check does not follow is not ours to judge).
     *
     * @return array<string, mixed>
     */
    private function inputs(mixed $inputs): array
    {
        $inputs = Hydrate::mapOrNull($inputs) ?? [];
        $ref = $inputs['$ref'] ?? null;

        if (count($inputs) === 1 && is_string($ref) && str_starts_with($ref, self::INPUT_REF)) {
            $named = (Hydrate::mapOrNull($this->components['inputs'] ?? null) ?? [])[str_replace(['~1', '~0'], ['/', '~'], substr($ref, strlen(self::INPUT_REF)))] ?? null;

            return Hydrate::mapOrNull($named) ?? $inputs;
        }

        return $inputs;
    }

    /**
     * The items of a list member, by index; anything else has none.
     *
     * @return list<mixed>
     */
    private static function items(mixed $list): array
    {
        return is_array($list) && array_is_list($list) ? $list : [];
    }
}
