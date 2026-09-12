<?php

declare(strict_types=1);

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Draft\ParameterDraft;
use Docuccino\Core\Extensions\Validation\DeepObjectMembers;
use Docuccino\Core\Patch\Contribution;

/**
 * The `required` list a deepObject container publishes, and the container's own requiredness derived
 * from it. Two facts with more than one producer each: a member's requiredness is stated per member by
 * producers a whole phase apart, and the container's flag is stated by one producer and derived from
 * another's evidence. Both are what tells a consumer whether a request they are about to send is one
 * the server will accept.
 */
function requiredMembersContainer(OperationDraft $operation): ParameterDraft
{
    $by = Contribution::integration('query-builder');

    $parameter = $operation->parameter('query', 'filter');
    $parameter->set('style', 'deepObject', $by);
    $parameter->set('explode', true, $by);
    $parameter->schema()->set('type', 'object', $by);

    return $parameter;
}

it('keeps every producer’s required member, whichever layer answered last', function (): void {
    $operation = new OperationDraft;
    $parameter = requiredMembersContainer($operation);

    // The attribute pass runs first, in the parameter phase; the rules recovery runs a phase later at a
    // LOWER layer. The contested unit is the member, not the list — a list merged and written whole is
    // one field, so the second producer's whole merge is shadowed and its member is simply lost.
    $first = new DeepObjectMembers($operation);
    $first->schemaFor('filter[status]')?->set('type', 'string', Contribution::attribute());
    $first->stateRequired('filter[status]', true);
    $first->flush(Contribution::attribute());

    $second = new DeepObjectMembers($operation);
    $second->schemaFor('filter[min_days]')?->set('type', 'integer', Contribution::integration('form-request'));
    $second->stateRequired('filter[min_days]', true);
    $second->flush(Contribution::integration('form-request'));

    expect($parameter->freeze()->toArray()['schema']['required'])->toBe(['status', 'min_days']);
});

it('lets the higher layer decide a member both producers named', function (): void {
    $operation = new OperationDraft;
    $parameter = requiredMembersContainer($operation);

    $low = new DeepObjectMembers($operation);
    $low->schemaFor('filter[status]')?->set('type', 'string', Contribution::integration('form-request'));
    $low->stateRequired('filter[status]', true);
    $low->flush(Contribution::integration('form-request'));

    // An author saying the member is optional is the ladder working, and it has to reach the list the
    // same way the requirement did.
    $high = new DeepObjectMembers($operation);
    $high->stateRequired('filter[status]', false);
    $high->flush(Contribution::attribute());

    $frozen = $parameter->freeze()->toArray();

    expect($frozen['schema'])->not->toHaveKey('required')
        ->and($frozen['schema']['properties'])->toHaveKey('status')
        ->and($frozen['required'] ?? null)->toBeNull();
});

it('keeps the list in the order it was built as producers add to it', function (): void {
    $operation = new OperationDraft;
    $parameter = requiredMembersContainer($operation);

    $by = Contribution::integration('form-request');
    $parameter->schema()->set('required', ['status'], $by);

    // Restating what a keyword already said moves nothing: a published list is read by people and by
    // generated clients, so an order that shuffles when an unrelated producer speaks is churn.
    $members = new DeepObjectMembers($operation);
    $members->stateRequired('filter[status]', true);
    $members->stateRequired('filter[min_days]', true);
    $members->flush($by);

    expect($parameter->freeze()->toArray()['schema']['required'])->toBe(['status', 'min_days']);
});

/*
 * The container's own requiredness. A required member has no parameter of its own here, so an optional
 * container publishes a request the server refuses — but the derivation is not a licence to outrank
 * everyone: the rule, stated here rather than read off the code, is that it carries the authority of
 * whoever stated the requirement, so it loses to a `required` stated STRICTLY above that and wins
 * against anything else, an unstated `required` included.
 */
it('derives the container’s requiredness with the authority of whoever required the member', function (
    ?string $states,
    string $requires,
    ?bool $published,
): void {
    $operation = new OperationDraft;
    $parameter = requiredMembersContainer($operation);

    if ($states !== null) {
        $parameter->setRequired(false, requiredMembersContribution($states));
    }

    $members = new DeepObjectMembers($operation);
    $members->schemaFor('filter[status]')?->set('type', 'string', requiredMembersContribution($requires));
    $members->stateRequired('filter[status]', true);
    $members->flush(requiredMembersContribution($requires));

    expect($parameter->freeze()->toArray()['required'])->toBe($published);
})->with([
    // Nobody stated it: the requirement is the only evidence there is.
    'unstated, required by an integration' => [null, 'integration', true],
    // The container producer's own `false` is its default, not an answer about a member it never saw.
    'an integration’s false against an integration’s requirement' => ['integration', 'integration', true],
    'an integration’s false against an attribute’s requirement' => ['integration', 'attribute', true],
    // An author's statement outranks the evidence, and JSON Schema says what they mean: send no
    // container and the request is valid, send one and it must carry the member.
    'an attribute’s false against an integration’s requirement' => ['attribute', 'integration', false],
    'an overlay’s false against an attribute’s requirement' => ['overlay', 'attribute', false],
    'a config false against an attribute’s requirement' => ['config', 'attribute', false],
    // Equal layers are not "strictly above", so the honest answer wins: a container that is optional
    // beside a member it requires is the one claim that cannot be true.
    'an attribute’s false against an attribute’s requirement' => ['attribute', 'attribute', true],
]);

function requiredMembersContribution(string $layer): Contribution
{
    return match ($layer) {
        'integration' => Contribution::integration('form-request'),
        'attribute' => Contribution::attribute(),
        'overlay' => Contribution::overlay(),
        'config' => Contribution::config(),
        default => Contribution::fallback(),
    };
}

it('requires the container for a member required below its own members', function (int $depth, bool $required): void {
    $operation = new OperationDraft;
    $by = Contribution::integration('form-request');
    $parameter = requiredMembersContainer($operation);

    $name = $depth === 1 ? 'filter[status]' : 'filter[window][from]';

    $members = new DeepObjectMembers($operation);
    $members->schemaFor($name)?->set('type', 'string', $by);
    $members->stateRequired($name, true);
    $members->flush($by);

    // Requirements are written at every depth the bracketed spelling reaches, so a reading that stops
    // at the container's own list answers about a subset of what was written — and the container comes
    // out optional for a member the server demands.
    expect($parameter->freeze()->toArray()['required'])->toBe($required);
})->with([
    'a member of the container' => [1, true],
    'a member of a member' => [2, true],
]);

it('requires the container for a member a declared shape requires at depth', function (): void {
    $operation = new OperationDraft;
    $parameter = requiredMembersContainer($operation);

    // The same fact arriving as one declared keyword rather than as nested drafts: `properties` written
    // whole is the depth a member list already reads, so the requirement read owes the same depth.
    $parameter->schema()->declareShape([
        'type' => 'object',
        'properties' => ['window' => ['type' => 'object', 'required' => ['from']]],
    ], Contribution::attribute());

    expect($parameter->freeze()->toArray()['required'])->toBeTrue();
});

it('stops requiring the container once the member behind it is gone', function (): void {
    $operation = new OperationDraft;
    $by = Contribution::integration('form-request');
    $parameter = requiredMembersContainer($operation);

    $members = new DeepObjectMembers($operation);
    $members->schemaFor('filter[window][from]')?->set('type', 'string', $by);
    $members->stateRequired('filter[window][from]', true);
    $members->flush($by);

    expect($parameter->freeze()->toArray()['required'])->toBeTrue();
    expect($members->remove('filter[window]'))->toBeTrue();

    // A subtraction takes the requirement with it at whatever depth it sat, so the container is not
    // left required because of a member nobody publishes.
    $frozen = $parameter->freeze()->toArray();

    expect($frozen['schema'])->not->toHaveKey('properties')
        ->and($frozen['required'] ?? null)->toBeNull();
});
