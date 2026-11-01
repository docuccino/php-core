<?php

declare(strict_types=1);

use Docuccino\Core\Document\Workflow\OutcomeAction;
use Docuccino\Core\Document\Workflow\StepOutcome;
use Docuccino\Core\Document\Workflow\StepResult;
use Docuccino\Core\Reader\Arazzo\ArazzoVersion;
use Docuccino\Core\Reader\Arazzo\SourceType;
use Docuccino\Core\Reader\ArazzoReader;
use Docuccino\Core\Reader\DocumentFinding;
use Docuccino\Core\Reader\DocumentReader;
use Docuccino\Core\Reader\FindingSource;
use Docuccino\Core\Reader\ReadFailure;
use Docuccino\Core\Reader\SourcePosition;
use Docuccino\Core\Reader\Syntax;
use Docuccino\Core\Reader\UnreadableDocumentException;

/*
 * Reading an Arazzo description someone else wrote: held to the schema for the version it declares, with
 * every finding placed at a line and a column, and what it states handed back in core's workflow model.
 */

/** @return array{string, ?string, ?array{line: int, column: int}} pointer, keyword, position */
function arazzoFindingRow(DocumentFinding $finding): array
{
    return [$finding->pointer, $finding->keyword, $finding->position?->toArray()];
}

function arazzoFixture(string $name): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/arazzo/'.$name);
}

it('reads a 1.1 description in YAML into the workflow model, every object of it', function (): void {
    $read = (new ArazzoReader)->read(arazzoFixture('applications.arazzo.yaml'));

    expect($read->syntax())->toBe(Syntax::Yaml)
        ->and($read->declaredVersion())->toBe('1.1.0')
        ->and($read->version())->toBe(ArazzoVersion::V1_1)
        ->and($read->validate()->findings)->toBe([]);

    $description = $read->description();
    [$apply, $screen] = $description->workflows;
    [$create, $find, $submit] = $apply->steps;

    expect([$description->title, $description->summary, $description->version])->toBe(['Applying for a vacancy', 'From candidate to application.', '2.0.0'])
        ->and($description->source('applications')?->url)->toBe('./applications.openapi.yaml')
        ->and($description->source('applications')?->type)->toBe(SourceType::OpenApi)
        // A source that does not say what it is says nothing, rather than a guess.
        ->and($description->source('candidates')?->type)->toBeNull()
        ->and($description->workflow('screen'))->toBe($screen)
        ->and($description->workflow('missing'))->toBeNull()
        ->and($description->components)->toHaveKeys(['inputs', 'parameters', 'failureActions'])

        // The inputs `$ref` is the reusable input it names.
        ->and($apply->inputs['required'] ?? null)->toBe(['email'])
        ->and($apply->outputs)->toBe(['candidateId' => '$steps.createCandidate.outputs.candidateId'])
        ->and($screen->dependsOn)->toBe(['applyForVacancy'])

        // Each step names its target as Arazzo does, and has no node id.
        ->and([$create->operation, $create->operationId])->toBe(['', '$sourceDescriptions.candidates.createCandidate'])
        ->and($find->operationPath)->toBe('{$sourceDescriptions.candidates.url}#/paths/~1candidates/get')
        ->and($submit->workflowId)->toBe('screen')

        ->and($create->body?->contentType)->toBe('application/json')
        ->and($create->body?->payload)->toBe(['email' => '$inputs.email'])
        ->and($create->body?->replacements)->toBe([['target' => '/name', 'value' => '$inputs.name']])
        ->and($create->outputs)->toBe(['candidateId' => '$response.body#/id'])
        ->and(array_map(static fn ($criterion): array => $criterion->toArray(), $create->successCriteria))->toBe([
            ['condition' => '$statusCode == 201', 'type' => 'simple'],
            ['condition' => '$.id', 'context' => '$response.body', 'type' => 'jsonpath', 'version' => 'rfc9535'],
        ]);

    // A step's own parameter overrides the workflow's of the same name and location, a reference takes the
    // component with the value written beside it, and what the workflow states for every step is added
    // where the step does not override it. A workflow's step passes its parameters with no location.
    $parameters = static fn ($step): array => array_map(static fn ($parameter): array => [$parameter->in, $parameter->name, $parameter->value], $step->parameters);

    expect($parameters($create))->toBe([['header', 'X-Tenant', 'acme'], ['header', 'Accept-Language', 'fr']])
        ->and($parameters($find))->toBe([['header', 'X-Tenant', '$inputs.tenant'], ['header', 'Accept-Language', 'en']])
        ->and($parameters($submit)[0])->toBe(['', 'candidateId', '$steps.createCandidate.outputs.candidateId']);

    $outcomes = static fn ($outcomes): array => array_map(
        static fn (StepOutcome $outcome): array => [$outcome->on->value, $outcome->action->value, $outcome->name, $outcome->stepId ?? $outcome->workflowId, $outcome->retryAfter, $outcome->retryLimit, count($outcome->criteria), $outcome->default],
        $outcomes,
    );

    // The workflow's failure action `unavailable` (a retry) applies to every step but the one that states
    // its own of that name (an end); Workflow::outcomes() adds what Arazzo implies where nothing holds
    // without a condition.
    expect($outcomes($apply->outcomes(0)))->toBe([
        ['success', 'goto', 'submit', 'submitApplication', null, null, 0, false],
        ['failure', 'goto', 'exists', 'findCandidate', null, null, 1, false],
        ['failure', 'retry', 'busy', null, 5, 3, 1, false],
        ['failure', 'retry', 'unavailable', null, 0.5, 1, 1, false],
        ['failure', 'end', null, null, null, null, 0, true],
    ])
        ->and($outcomes($apply->outcomes(1)))->toBe([
            ['success', 'goto', 'screen', 'screen', null, null, 0, false],
            ['failure', 'end', 'unavailable', null, null, null, 0, false],
        ])
        ->and($outcomes($apply->outcomes(2)))->toBe([
            ['success', 'end', null, null, null, null, 0, true],
            ['failure', 'retry', 'unavailable', null, 0.5, 1, 1, false],
            ['failure', 'end', null, null, null, null, 0, true],
        ]);
});

it('reads a 1.0 description in JSON, with a criterion\'s expression type spelt beside it', function (): void {
    $read = (new ArazzoReader)->read(arazzoFixture('payments.arazzo.json'));
    $pay = $read->description()->workflows[0];

    expect($read->syntax())->toBe(Syntax::Json)
        ->and($read->version())->toBe(ArazzoVersion::V1_0)
        ->and($read->validate()->isValid())->toBeTrue()
        ->and($pay->steps[0]->successCriteria[0]->toArray())->toBe(['condition' => '$.status', 'context' => '$response.body', 'type' => 'jsonpath', 'version' => 'draft-goessner-dispatch-jsonpath-00'])
        ->and(array_map(static fn (StepOutcome $outcome): array => [$outcome->on, $outcome->action, $outcome->stepId, $outcome->default], $pay->outcomes(0)))->toBe([
            [StepResult::Success, OutcomeAction::Goto, 'receipt', true],
            [StepResult::Failure, OutcomeAction::End, null, false],
            [StepResult::Failure, OutcomeAction::End, null, true],
        ]);
});

it('places where a workflow and a step are written, one at a time or all at once', function (): void {
    $read = (new ArazzoReader)->read(arazzoFixture('applications.arazzo.yaml'));

    expect($read->position('/workflows/0')?->toArray())->toBe(['line' => 13, 'column' => 3])
        ->and(array_map(static fn (?SourcePosition $position): ?array => $position?->toArray(), $read->positions(['/workflows/0/steps/1', '/workflows/1/steps/0/operationId', '/workflows/9'])))->toBe([
            '/workflows/0/steps/1' => ['line' => 66, 'column' => 7],
            '/workflows/1/steps/0/operationId' => ['line' => 87, 'column' => 9],
            '/workflows/9' => null,
        ]);
});

it('fails a description at every place it breaks its version\'s schema, each at its line and column', function (): void {
    $yaml = <<<'YAML'
        arazzo: 1.1.0
        info:
          title: Broken
          version: '1'
        sourceDescriptions:
          - name: has spaces
            url: ./a.yaml
        workflows:
          - workflowId: w
            steps:
              - stepId: s
                operationId: op
                onFailure:
                  - name: again
                    type: retry
                    retryLimit: -1
                unknown: member
        YAML;

    $validation = (new ArazzoReader)->read($yaml)->validate();

    // opis meets the retry limit once for each of a step's three `oneOf` shapes; it is one finding. And a
    // failure action that is not one may be a reusable reference instead, which is said too.
    expect($validation->findingsFrom(FindingSource::Arazzo))->toBe($validation->findings)
        ->and(array_map(arazzoFindingRow(...), $validation->findings))->toBe([
            ['/sourceDescriptions/0/name', 'pattern', ['line' => 6, 'column' => 5]],
            ['/workflows/0/steps/0/onFailure/0/retryLimit', 'minimum', ['line' => 16, 'column' => 13]],
            ['/workflows/0/steps/0/onFailure/0', 'required', ['line' => 14, 'column' => 11]],
        ]);
});

it('finds a member no object of the version may carry', function (): void {
    $yaml = <<<'YAML'
        arazzo: 1.0.0
        info:
          title: T
          version: '1'
        sourceDescriptions: [{name: a, url: ./a.yaml}]
        workflows:
          - workflowId: w
            steps:
              - stepId: s
                operationId: op
                unknown: member
        YAML;

    expect(array_map(arazzoFindingRow(...), (new ArazzoReader)->read($yaml)->validate()->findings))->toBe([
        ['/workflows/0/steps/0', 'unevaluatedProperties', ['line' => 9, 'column' => 7]],
    ]);
});

it('holds a description to the version it declares, not the newest', function (): void {
    // A channelPath step is Arazzo 1.1's; at 1.0 a step has no such member.
    $description = static fn (string $version): string => <<<YAML
        arazzo: {$version}
        info: {title: Events, version: '1'}
        sourceDescriptions: [{name: events, url: ./events.yaml, type: asyncapi}]
        workflows:
          - workflowId: listen
            steps:
              - {stepId: wait, channelPath: '{\$sourceDescriptions.events.url}#/channels/applied', action: receive}
        YAML;

    $v11 = (new ArazzoReader)->read($description('1.1.0'));

    expect($v11->validate()->isValid())->toBeTrue()
        ->and($v11->description()->workflows[0]->steps[0]->channelPath)->toBe('{$sourceDescriptions.events.url}#/channels/applied')
        ->and((new ArazzoReader)->read($description('1.0.0'))->validate()->isValid())->toBeFalse();
});

it('finds a components reference that names nothing, and leaves it out of the workflow', function (): void {
    $yaml = <<<'YAML'
        arazzo: 1.0.0
        info: {title: Refs, version: '1'}
        sourceDescriptions: [{name: api, url: ./api.yaml}]
        workflows:
          - workflowId: w
            failureActions:
              - reference: $components.failureActions.gone
            steps:
              - stepId: s
                operationId: op
                parameters:
                  - reference: $components.parameters.missing
                onSuccess:
                  - reference: $components.failureActions.wrongKind
        components:
          failureActions:
            wrongKind: {name: wrongKind, type: end}
        YAML;

    $read = (new ArazzoReader)->read($yaml);
    $step = $read->description()->workflows[0]->steps[0];

    // The workflow's failure action is reported once, where it is written, not once for every step.
    expect(array_map(arazzoFindingRow(...), $read->validate()->findings))->toBe([
        ['/workflows/0/failureActions/0/reference', 'reference', ['line' => 7, 'column' => 9]],
        ['/workflows/0/steps/0/parameters/0/reference', 'reference', ['line' => 12, 'column' => 13]],
        ['/workflows/0/steps/0/onSuccess/0/reference', 'reference', ['line' => 14, 'column' => 13]],
    ])
        ->and($read->validate()->findings[1]->message)->toBe('"$components.parameters.missing" names nothing in the description\'s components.parameters')
        ->and($step->parameters)->toBe([])
        ->and($step->outcomes)->toBe([]);
});

it('reports a version it has no schema for as the one finding, rather than check it against another', function (?string $declared, string $pointer): void {
    $read = (new ArazzoReader)->read(($declared === null ? '' : "arazzo: {$declared}\n")."info: {title: T, version: '1'}\n");
    $findings = $read->validate()->findings;

    expect($read->version())->toBeNull()
        ->and($findings)->toHaveCount(1)
        ->and([$findings[0]->source, $findings[0]->pointer, $findings[0]->keyword])->toBe([FindingSource::Arazzo, $pointer, 'arazzo'])
        ->and($findings[0]->message)->toContain('(1.0, 1.1)');
})->with([
    'a later version' => ['1.2.0', '/arazzo'],
    'another major' => ['2.0.0', '/arazzo'],
    'none at all' => [null, '/'],
]);

it('reads a version by its major and minor', function (?string $declared, ?ArazzoVersion $version): void {
    expect(ArazzoVersion::of($declared))->toBe($version);
})->with([
    ['1.0.0', ArazzoVersion::V1_0],
    ['1.0.1', ArazzoVersion::V1_0],
    ['1.1.0', ArazzoVersion::V1_1],
    ['1.1', ArazzoVersion::V1_1],
    ['1.1.0-rc1', ArazzoVersion::V1_1],
    ['1.2.0', null],
    ['one', null],
    [null, null],
]);

it('says a validation was cut short without a finding saying so', function (): void {
    $steps = implode('', array_map(static fn (int $i): string => "      - {stepId: s{$i}, operationId: op, bogus: {$i}}\n", range(1, 5)));
    $validation = (new ArazzoReader)->read("arazzo: 1.1.0\ninfo: {title: T, version: '1'}\nsourceDescriptions: [{name: a, url: ./a.yaml}]\nworkflows:\n  - workflowId: w\n    steps:\n{$steps}")->validate(2);

    expect($validation->findings)->toHaveCount(2)
        ->and($validation->isTruncated())->toBeTrue();
});

it('refuses no finding limit', function (): void {
    expect(static fn () => (new ArazzoReader)->read(arazzoFixture('payments.arazzo.json'))->validate(0))
        ->toThrow(InvalidArgumentException::class);
});

it('reads under the bounds every document is read under', function (string $bytes, ReadFailure $failure): void {
    expect(static fn () => (new ArazzoReader)->read($bytes))
        ->toThrow(static function (UnreadableDocumentException $exception) use ($failure): void {
            expect($exception->failure)->toBe($failure);
        });
})->with([
    'aliases expanding it far past its size' => [
        "arazzo: 1.1.0\na: &a [\"lol\",\"lol\",\"lol\",\"lol\",\"lol\",\"lol\",\"lol\",\"lol\",\"lol\"]\n"
            .implode('', array_map(static fn (string $from, string $to): string => "{$to}: &{$to} [*{$from},*{$from},*{$from},*{$from},*{$from},*{$from},*{$from},*{$from},*{$from}]\n", range('a', 'h'), range('b', 'i'))),
        ReadFailure::AliasExpansion,
    ],
    'nesting deeper than a document can be written' => [str_repeat('[', 200).str_repeat(']', 200), ReadFailure::TooDeep],
    'not an object' => ['"arazzo"', ReadFailure::NotAnObject],
]);

it('writes the description back as JSON that reads as the same description', function (): void {
    $yaml = (new ArazzoReader)->read(arazzoFixture('applications.arazzo.yaml'));
    $json = (new ArazzoReader)->read($yaml->json());

    expect($json->syntax())->toBe(Syntax::Json)
        ->and($json->json())->toBe($yaml->json())
        ->and($json->description())->toEqual($yaml->description());
});

it('tells a reader of OpenAPI that a document is an Arazzo description', function (): void {
    expect((new DocumentReader)->read(arazzoFixture('applications.arazzo.yaml'))->inspect()->arazzo)->toBe('1.1.0')
        ->and((new DocumentReader)->read('{"openapi": "3.1.0", "info": {"title": "T", "version": "1"}, "paths": {}}')->inspect()->arazzo)->toBeNull();
});
