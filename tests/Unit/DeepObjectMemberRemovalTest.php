<?php

declare(strict_types=1);

use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Draft\ParameterDraft;
use Docuccino\Core\Extensions\Validation\DeepObjectMembers;
use Docuccino\Core\Patch\Contribution;

/**
 * Taking one member off a deepObject container. A subtraction leaves no evidence — the document a
 * removal that reached nothing produces is the document a working one produces — so what is pinned here
 * is both halves: what the container publishes afterwards, and the ANSWER the caller judges its
 * declaration by, because that answer is the only thing standing between a typo and silence.
 */
function deepObjectContainer(OperationDraft $operation, string $name = 'filter'): ParameterDraft
{
    $by = Contribution::integration('query-builder');

    $parameter = $operation->parameter('query', $name);
    $parameter->setRequired(false, $by);
    $parameter->set('style', 'deepObject', $by);
    $parameter->set('explode', true, $by);
    $parameter->schema()->set('type', 'object', $by);
    $parameter->schema()->property('status')->set('type', 'string', $by);
    $parameter->schema()->property('opaque')->set('type', 'string', $by);

    return $parameter;
}

it('stops publishing the member, and stops the container requiring it', function (): void {
    $operation = new OperationDraft;
    $parameter = deepObjectContainer($operation);

    $members = new DeepObjectMembers($operation);
    $members->stateRequired('filter[opaque]', true);
    $members->flush(Contribution::integration('validation-rules'));

    // Anti-vacuity: the member really is published and really is required before the removal, so the
    // assertions below are about the subtraction and not about a producer that never ran.
    $before = $parameter->freeze()->toArray();
    expect($before['schema']['properties'])->toHaveKey('opaque')
        ->and($before['schema']['required'])->toBe(['opaque'])
        ->and($before['required'])->toBeTrue();

    expect($members->remove('filter[opaque]'))->toBeTrue();

    $after = $parameter->freeze()->toArray();

    // The member is gone from the object, and the `required` list does not name it. A list naming a
    // member nobody publishes tells a consumer their request must carry a value the document does not
    // describe — and OMITS the keyword rather than publishing `required: []`, which states nothing.
    expect(array_keys($after['schema']['properties']))->toBe(['status'])
        ->and($after['schema'])->not->toHaveKey('required')
        // The container was required BECAUSE of that member, so its own requiredness falls back to what
        // its producer stated. This is what the bracketed representation does when the same declaration
        // drops a required parameter: the requirement leaves the document with the value it was about.
        ->and($after['required'])->toBeFalse();
});

it('keeps requiring the members it still publishes', function (): void {
    $operation = new OperationDraft;
    $parameter = deepObjectContainer($operation);

    $members = new DeepObjectMembers($operation);
    $members->stateRequired('filter[status]', true);
    $members->stateRequired('filter[opaque]', true);
    $members->flush(Contribution::integration('validation-rules'));

    expect($members->remove('filter[opaque]'))->toBeTrue();

    $after = $parameter->freeze()->toArray();

    expect($after['schema']['required'])->toBe(['status'])
        ->and($after['required'])->toBeTrue();
});

it('reaches a member nested below the container', function (): void {
    $operation = new OperationDraft;
    $by = Contribution::integration('query-builder');
    $parameter = deepObjectContainer($operation);

    $window = $parameter->schema()->property('window');
    $window->set('type', 'object', $by);
    $window->property('from')->set('type', 'string', $by);
    $window->property('to')->set('type', 'string', $by);

    $members = new DeepObjectMembers($operation);
    $members->stateRequired('filter[window][from]', true);
    $members->flush(Contribution::integration('validation-rules'));

    expect($members->memberNames())->toBe([
        'filter[opaque]',
        'filter[status]',
        'filter[window]',
        'filter[window][from]',
        'filter[window][to]',
    ]);

    expect($members->remove('filter[window][from]'))->toBeTrue();

    $schema = $parameter->freeze()->toArray()['schema'];

    expect($schema['properties']['window']['properties'])->toHaveKey('to')
        ->and($schema['properties']['window']['properties'])->not->toHaveKey('from')
        ->and($schema['properties']['window'])->not->toHaveKey('required');
});

it('reaches a member a declared shape published as one keyword, not as a draft', function (): void {
    // An attribute-declared object supersedes the integration's property drafts and leaves `properties`
    // as one keyword value. A removal reading only the drafts would find nothing here and report the
    // author's own declaration as having matched nothing.
    $operation = new OperationDraft;
    $parameter = deepObjectContainer($operation);
    $parameter->schema()->declareShape(
        ['type' => 'object', 'properties' => ['status' => ['type' => 'string'], 'opaque' => ['type' => 'integer']]],
        Contribution::attribute(),
    );

    $members = new DeepObjectMembers($operation);

    expect($members->memberNames())->toBe(['filter[opaque]', 'filter[status]'])
        ->and($members->remove('filter[opaque]'))->toBeTrue()
        ->and($parameter->freeze()->toArray()['schema']['properties'])->toBe(['status' => ['type' => 'string']]);
});

it('omits `properties` once the last member it published is gone', function (): void {
    $operation = new OperationDraft;
    $parameter = deepObjectContainer($operation);
    $parameter->schema()->declareShape(
        ['type' => 'object', 'properties' => ['opaque' => ['type' => 'string']]],
        Contribution::attribute(),
    );

    expect((new DeepObjectMembers($operation))->remove('filter[opaque]'))->toBeTrue();

    $schema = $parameter->freeze()->toArray()['schema'];

    // An object describing no members is vague and true — `properties: {}` says the same thing in a
    // shape a generated client reads as a closed empty object.
    expect($schema)->not->toHaveKey('properties')
        ->and($schema['type'])->toBe('object');
});

it('says no, and mints nothing, for a name no container publishes', function (string $name): void {
    $operation = new OperationDraft;
    $parameter = deepObjectContainer($operation);

    $parameter->schema()->property('window')->set('type', 'object', Contribution::integration('query-builder'));
    $flat = $operation->parameter('query', 'sort');
    $flat->schema()->set('type', 'string', Contribution::integration('query-builder'));

    $before = $operation->freeze()->toArray();

    expect((new DeepObjectMembers($operation))->remove($name))->toBeFalse()
        // Minting is the trap: the additive read creates the member it does not find, and a subtraction
        // walking the same path would publish the very key it was asked to take away.
        ->and($operation->freeze()->toArray())->toBe($before);
})->with([
    // Not bracketed at all: the name is a parameter, and dropping it is the caller's other path.
    'a flat name' => ['filter'],
    // No parameter of that name on this operation.
    'a container nothing published' => ['nosuch[status]'],
    // A parameter, but not one whose members ride as brackets — `sort` is its own value.
    'a container that is not a deepObject' => ['sort[status]'],
    // The container publishes members, just not this one.
    'a member nobody published' => ['filter[statuss]'],
    // The depth exists, the leaf does not.
    'a leaf nobody published under a member' => ['filter[window][from]'],
    // No such depth at all.
    'a depth nobody published' => ['filter[nowhere][from]'],
]);

it('lists the members of every deepObject container and nothing else', function (): void {
    $operation = new OperationDraft;
    $by = Contribution::integration('query-builder');

    deepObjectContainer($operation);
    deepObjectContainer($operation, 'fields');

    // A flat parameter with a schema of its own, and a header that happens to share the container's
    // name — neither is a deepObject query container, so neither contributes a member.
    $operation->parameter('query', 'sort')->schema()->set('type', 'string', $by);
    $header = $operation->parameter('header', 'filter');
    $header->set('style', 'deepObject', $by);
    $header->schema()->property('leaked')->set('type', 'string', $by);

    expect((new DeepObjectMembers($operation))->memberNames())->toBe([
        'fields[opaque]',
        'fields[status]',
        'filter[opaque]',
        'filter[status]',
    ]);
});

/*
 * The judge and the remover, on one name. A subtraction leaves no evidence, so the ANSWER a caller
 * judges its declaration by is the only thing between a typo and silence — and an answer computed a
 * different way from the removal is two readings of one question, which is how a member the author
 * marked as not-for-publication stays published with no report beside it.
 */
it('judges a bracketed name by exactly what the removal reaches', function (string $name, bool $reaches): void {
    $operation = new OperationDraft;
    $by = Contribution::integration('query-builder');
    $parameter = deepObjectContainer($operation);

    // Two members whose names the bracketed spelling and the path grammar disagree about.
    $parameter->schema()->property('a[b')->set('type', 'string', $by);
    $parameter->schema()->property('a]b')->set('type', 'string', $by);

    $members = new DeepObjectMembers($operation);
    $before = $parameter->freeze()->toArray();

    expect($members->publishes($name))->toBe($reaches)
        ->and($members->remove($name))->toBe($reaches);

    if (! $reaches) {
        // The other half of the same defect: a name that reaches nothing must leave the document
        // alone, or a declaration reported as having matched nothing has silently mutated the node.
        expect($parameter->freeze()->toArray())->toBe($before);
    }
})->with([
    'a member spelled as the write spells it' => ['filter[opaque]', true],
    // `?filter[a[b]=x` sets the `a[b` key of `filter` on the wire, so this reaches that member. Read
    // as two segments it would descend into an `a` nobody publishes and remove nothing, silently.
    'a member whose name holds an opening bracket' => ['filter[a[b]', true],
    // Unbalanced: on the wire this is a top-level `filter_opaque`, so it names no member. Read as a
    // path it would remove `opaque`, a member the author never mentioned.
    'an unterminated name' => ['filter[opaque', false],
    // A member whose name holds a `]` is published and has no bracketed spelling, so no declaration
    // addresses it — reported rather than guessed at.
    'a member whose name holds a closing bracket' => ['filter[a]b]', false],
    'an empty member' => ['filter[]', false],
]);

it('publishes a numeric member name as the string `required` is required to hold', function (): void {
    $operation = new OperationDraft;
    $by = Contribution::integration('query-builder');
    $parameter = deepObjectContainer($operation);

    $members = new DeepObjectMembers($operation);
    $members->schemaFor('filter[2024]')?->set('type', 'string', $by);
    $members->stateRequired('filter[2024]', true);
    $members->flush($by);

    // PHP normalises the array key `"2024"` to an int, and `required` is an array of STRINGS — an
    // integer there is a document a validator rejects, and the numbers a generated client would read
    // as positions.
    expect($parameter->freeze()->toArray()['schema']['required'])->toBe(['2024']);

    expect($members->remove('filter[2024]'))->toBeTrue();

    $frozen = $parameter->freeze()->toArray();

    // And the subtraction takes it with it: a `required` naming a member with no `properties` entry
    // tells a consumer their request must carry a value the document does not describe, and the
    // container was promoted to required off that phantom.
    expect($frozen['schema'])->not->toHaveKey('required')
        ->and($frozen['schema']['properties'])->not->toHaveKey('2024')
        ->and($frozen['required'])->toBeFalse();
});

it('stops offering a member it published as a declared keyword once that member is gone', function (): void {
    $operation = new OperationDraft;
    $parameter = deepObjectContainer($operation);
    $parameter->schema()->declareShape(
        ['type' => 'object', 'properties' => ['status' => ['type' => 'string'], 'opaque' => ['type' => 'integer']]],
        Contribution::attribute(),
    );

    $members = new DeepObjectMembers($operation);

    expect($members->remove('filter[opaque]'))->toBeTrue();

    // The list a report hands the reader, and the answer a second declaration is judged by. A keyword
    // `properties` is not mutated by a removal — freeze() applies it — so a member list reading the
    // keyword alone goes on offering a member nothing publishes, and a second declaration naming it
    // is told it matched.
    expect($members->memberNames())->toBe(['filter[status]'])
        ->and($members->publishes('filter[opaque]'))->toBeFalse()
        ->and($members->remove('filter[opaque]'))->toBeFalse();
});
