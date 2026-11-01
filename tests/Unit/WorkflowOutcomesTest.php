<?php

declare(strict_types=1);

use Docuccino\Core\Document\Workflow\Criterion;
use Docuccino\Core\Document\Workflow\OutcomeAction;
use Docuccino\Core\Document\Workflow\StepBody;
use Docuccino\Core\Document\Workflow\StepOutcome;
use Docuccino\Core\Document\Workflow\StepParameter;
use Docuccino\Core\Document\Workflow\StepResult;
use Docuccino\Core\Document\Workflow\Workflow;
use Docuccino\Core\Document\Workflow\WorkflowExtension;
use Docuccino\Core\Document\Workflow\WorkflowStep;
use Docuccino\Core\Support\JsonValue;

/*
 * Outcomes in the workflow model: what a step does next on success and on failure. A declared workflow
 * states none, an Arazzo one may, and both answer `outcomes()` alike — Arazzo's own rule for a step that
 * says nothing: success runs the next step (or ends after the last), failure ends the workflow.
 */

/** @return list<array{string, string, ?string, bool}> on, action, step, default */
function outcomeRows(Workflow $workflow, int $index): array
{
    return array_map(
        static fn (StepOutcome $outcome): array => [$outcome->on->value, $outcome->action->value, $outcome->stepId, $outcome->default],
        $workflow->outcomes($index),
    );
}

it('gives a declared step the outcomes Arazzo implies when a step states none', function (): void {
    $workflow = Workflow::fromArray([
        'id' => 'checkout',
        'steps' => [
            ['id' => 'reserve', 'operation' => 'op:v1:aaaaaabasketshld'],
            ['id' => 'pay', 'operation' => 'op:v1:aaaaaaapaymentnw'],
        ],
    ]);

    expect(outcomeRows($workflow, 0))->toBe([
        ['success', 'goto', 'pay', true],
        ['failure', 'end', null, true],
    ])
        // From the last step success has nowhere to go but the end.
        ->and(outcomeRows($workflow, 1))->toBe([
            ['success', 'end', null, true],
            ['failure', 'end', null, true],
        ]);
});

it('keeps the outcomes a step states, success first, and adds a default only where none holds otherwise', function (): void {
    $retry = new StepOutcome(StepResult::Failure, OutcomeAction::Retry, name: 'busy', retryAfter: 5, retryLimit: 3, criteria: [new Criterion('$statusCode == 503')]);
    $found = new StepOutcome(StepResult::Failure, OutcomeAction::Goto, name: 'exists', stepId: 'find', criteria: [new Criterion('$statusCode == 409')]);
    $done = new StepOutcome(StepResult::Success, OutcomeAction::Goto, name: 'next', workflowId: 'apply');

    $workflow = new Workflow('register', [
        new WorkflowStep('create', '', operationId: 'createCandidate', outcomes: [$found, $retry, $done]),
        new WorkflowStep('find', '', operationId: 'findCandidate'),
    ]);

    $outcomes = $workflow->outcomes(0);

    // Success's stated outcome has no criteria, so it is the "otherwise" and no default is added for it;
    // failure's are both conditional, so the end Arazzo implies follows them.
    expect($outcomes)->toHaveCount(4)
        ->and($outcomes[0])->toBe($done)
        ->and($outcomes[1])->toBe($found)
        ->and($outcomes[2])->toBe($retry)
        ->and([$outcomes[3]->on, $outcomes[3]->action, $outcomes[3]->default])->toBe([StepResult::Failure, OutcomeAction::End, true])
        ->and($outcomes[2]->retryAfter)->toBe(5)
        ->and($outcomes[2]->retryLimit)->toBe(3);
});

it('refuses a step the workflow has not got', function (): void {
    expect(static fn () => (new Workflow('empty', []))->outcomes(0))
        ->toThrow(OutOfRangeException::class, 'The workflow "empty" has no step 0.');
});

/**
 * A declared workflow is part of the UIR and of its content hash, so the new members must not appear in
 * what it writes: every workflow in the fixture writes back exactly what it was read from.
 */
it('writes a declared workflow exactly as the UIR states it', function (): void {
    $document = JsonValue::decode((string) file_get_contents(dirname(__DIR__).'/Fixtures/workflows.uir.json'));
    $declared = is_array($document) && is_array($document['x-docuccino'] ?? null) ? $document['x-docuccino']['workflows'] ?? null : null;
    $declared = is_array($declared) ? array_values($declared) : [];

    expect($declared)->not->toBe([])
        ->and(WorkflowExtension::fromArray($declared)->toArray())->toBe($declared);
});

it('reads back every member it writes for a workflow read from Arazzo', function (): void {
    $workflow = new Workflow(
        id: 'apply',
        steps: [
            new WorkflowStep(
                id: 'submit',
                operation: '',
                description: 'Submit the application.',
                parameters: [new StepParameter('vacancyId', 'path', '$inputs.vacancyId')],
                body: new StepBody('application/json', ['email' => '$inputs.email'], [['target' => '/name', 'value' => '$inputs.name']]),
                outputs: ['id' => '$response.body#/id'],
                operationPath: '{$sourceDescriptions.vacancies.url}#/paths/~1applications/post',
                successCriteria: [new Criterion('$.status', 'jsonpath', '$response.body', 'draft-goessner-dispatch-jsonpath-00')],
                outcomes: [new StepOutcome(StepResult::Failure, OutcomeAction::Retry, 'again', retryAfter: 1.5, retryLimit: 2, criteria: [new Criterion('$statusCode == 503')])],
            ),
            new WorkflowStep('screen', '', workflowId: 'screening'),
            new WorkflowStep('notify', '', operationId: 'notify', channelPath: '{$sourceDescriptions.events.url}#/channels/applied'),
        ],
        summary: 'Apply',
        inputs: ['type' => 'object'],
        outputs: ['applicationId' => '$steps.submit.outputs.id'],
        dependsOn: ['register'],
    );

    $read = Workflow::fromArray($workflow->toArray());

    expect($read)->toEqual($workflow)
        ->and($read->toArray())->toBe($workflow->toArray());
});

it('reads a criterion type written as Arazzo\'s expression type object', function (): void {
    $criterion = Criterion::fromArray(['condition' => '$.ok', 'context' => '$response.body', 'type' => ['type' => 'jsonpath', 'version' => 'rfc9535']]);

    expect([$criterion->type, $criterion->version, $criterion->context])->toBe(['jsonpath', 'rfc9535', '$response.body'])
        ->and(Criterion::fromArray(['condition' => '$statusCode == 200'])->type)->toBe('simple');
});

it('drops an outcome whose result or action it does not know', function (string $on, string $action): void {
    expect(StepOutcome::fromArray(['on' => $on, 'action' => $action]))->toBeNull();
})->with([
    'an unknown result' => ['timeout', 'end'],
    'an unknown action' => ['failure', 'skip'],
]);

it('reads every result and every action it knows', function (StepResult $on, OutcomeAction $action): void {
    $outcome = StepOutcome::fromArray(['on' => $on->value, 'action' => $action->value]);

    expect($outcome?->on)->toBe($on)
        ->and($outcome?->action)->toBe($action);
})->with(static function (): iterable {
    foreach (StepResult::cases() as $on) {
        foreach (OutcomeAction::cases() as $action) {
            yield $on->value.' '.$action->value => [$on, $action];
        }
    }
});
