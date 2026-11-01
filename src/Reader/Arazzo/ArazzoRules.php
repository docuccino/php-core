<?php

declare(strict_types=1);

namespace Docuccino\Core\Reader\Arazzo;

use Docuccino\Core\SpecValidation\Finding;
use Docuccino\Core\Support\Hydrate;

/**
 * The rules Arazzo states that its JSON Schema cannot: a source description's `name`, a workflow's
 * `workflowId` and, within its workflow, a step's `stepId` are unique; a workflow named by a step, a
 * `goto` or `dependsOn`, or a step named by a `goto`, exists. A workflow in another source
 * (`$sourceDescriptions.{name}.{workflowId}`) is another document's question, so it is not asked.
 *
 * Read as written: a member of the wrong shape is the schema's to report, and is passed over here. An
 * action named by `reference` is checked where the reference resolves to, once, in its components; a
 * `goto` there may name any workflow's step, so only its `workflowId` is.
 *
 * @internal
 */
final class ArazzoRules
{
    private const string OTHER_SOURCE = '$sourceDescriptions.';

    /** @var list<Finding> */
    private array $found = [];

    /** @var list<string> */
    private array $workflowIds = [];

    /**
     * @param  array<string, mixed>  $document
     * @return list<Finding>
     */
    public static function check(array $document): array
    {
        $rules = new self;
        $rules->unique(self::items($document['sourceDescriptions'] ?? null), 'name', '/sourceDescriptions', 'source description');

        $workflows = self::items($document['workflows'] ?? null);
        $rules->workflowIds = $rules->unique($workflows, 'workflowId', '/workflows', 'workflow');

        foreach ($workflows as $index => $workflow) {
            $rules->workflow(Hydrate::mapOrNull($workflow) ?? [], '/workflows/'.$index);
        }

        $components = Hydrate::mapOrNull($document['components'] ?? null) ?? [];
        foreach (['successActions', 'failureActions'] as $kind) {
            foreach (Hydrate::mapOrNull($components[$kind] ?? null) ?? [] as $name => $action) {
                $rules->action(Hydrate::mapOrNull($action) ?? [], '/components/'.$kind.'/'.self::escape((string) $name), null);
            }
        }

        return $rules->found;
    }

    /**
     * @param  array<string, mixed>  $workflow
     */
    private function workflow(array $workflow, string $at): void
    {
        foreach (self::items($workflow['dependsOn'] ?? null) as $index => $id) {
            $this->workflowExists($id, $at.'/dependsOn/'.$index);
        }

        $steps = self::items($workflow['steps'] ?? null);
        $stepIds = $this->unique($steps, 'stepId', $at.'/steps', 'step in this workflow');

        foreach (['successActions', 'failureActions'] as $kind) {
            foreach (self::items($workflow[$kind] ?? null) as $index => $action) {
                $this->action(Hydrate::mapOrNull($action) ?? [], $at.'/'.$kind.'/'.$index, $stepIds);
            }
        }

        foreach ($steps as $index => $step) {
            $step = Hydrate::mapOrNull($step) ?? [];
            $stepAt = $at.'/steps/'.$index;
            $this->workflowExists($step['workflowId'] ?? null, $stepAt.'/workflowId');

            foreach (['onSuccess', 'onFailure'] as $kind) {
                foreach (self::items($step[$kind] ?? null) as $actionIndex => $action) {
                    $this->action(Hydrate::mapOrNull($action) ?? [], $stepAt.'/'.$kind.'/'.$actionIndex, $stepIds);
                }
            }
        }
    }

    /**
     * A `goto` action's target. $stepIds null where the action is reusable, and so its steps unknown.
     *
     * @param  array<string, mixed>  $action
     * @param  list<string>|null  $stepIds
     */
    private function action(array $action, string $at, ?array $stepIds): void
    {
        if (($action['type'] ?? null) !== 'goto') {
            return;
        }

        $this->workflowExists($action['workflowId'] ?? null, $at.'/workflowId');

        $stepId = $action['stepId'] ?? null;
        if ($stepIds !== null && is_string($stepId) && ! in_array($stepId, $stepIds, true)) {
            $this->found[] = new Finding($at.'/stepId', 'stepId', sprintf('"%s" names no step in this workflow.', $stepId));
        }
    }

    private function workflowExists(mixed $id, string $at): void
    {
        if (is_string($id) && ! str_starts_with($id, self::OTHER_SOURCE) && ! in_array($id, $this->workflowIds, true)) {
            $this->found[] = new Finding($at, 'workflowId', sprintf('"%s" names no workflow in this description.', $id));
        }
    }

    /**
     * Every $member of $items that an earlier one already has, reported where it is written; the ids found.
     *
     * @param  list<mixed>  $items
     * @return list<string>
     */
    private function unique(array $items, string $member, string $at, string $what): array
    {
        $seen = [];
        foreach ($items as $index => $item) {
            $id = (Hydrate::mapOrNull($item) ?? [])[$member] ?? null;
            if (! is_string($id)) {
                continue;
            }

            if (in_array($id, $seen, true)) {
                $this->found[] = new Finding($at.'/'.$index.'/'.$member, $member, sprintf('"%s" is already the %s of an earlier %s; each must be unique.', $id, $member, $what));
            } else {
                $seen[] = $id;
            }
        }

        return $seen;
    }

    /**
     * @return list<mixed>
     */
    private static function items(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? $value : [];
    }

    private static function escape(string $token): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $token);
    }
}
