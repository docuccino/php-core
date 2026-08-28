<?php

declare(strict_types=1);

use Docuccino\Core\Contract\ContractParameter;
use Docuccino\Core\Contract\ParameterSchema;
use Docuccino\Core\Contract\ParameterSchemaKind;

/**
 * The four-way answer a documented parameter makes about what its values can be held to.
 *
 * A nullable schema said "no" three different ways — no member, a member no validator can take, and a
 * `content` object documented instead — and the boolean beside it that told the last two apart was
 * opt-in. Every shape a document can put there is a row here, and each names its KIND, so a reader
 * that starts answering "absent" where the document wrote `content` fails rather than quietly
 * changing which sentence a suite is warned with.
 *
 * The third column is `'42'` read back through the answer, which is the only reading of the keywords
 * there is: it is the integer only where the document published keywords that say so, and the string
 * as it arrived everywhere else — a boolean schema included, since there is nothing on one to read.
 */
it('reads every shape a document can put where a parameter schema goes', function (array $definition, ParameterSchemaKind $kind, mixed $read): void {
    $schema = ParameterSchema::of($definition);

    expect($schema->kind)->toBe($kind)
        ->and($schema->read('42', []))->toBe($read);
})->with([
    'a schema' => [['schema' => ['type' => 'integer']], ParameterSchemaKind::Schema, 42],
    // `[]` is how associative decoding spells `{}`, and the empty schema accepts everything.
    'the empty schema' => [['schema' => []], ParameterSchemaKind::Schema, '42'],
    // `true` and `false` ARE schemas; there are simply no keywords on them to read a wire value back
    // against, which is the same nothing `{}` offers. The validator gets the node by pointer regardless.
    'the boolean schema true' => [['schema' => true], ParameterSchemaKind::Schema, '42'],
    'the boolean schema false' => [['schema' => false], ParameterSchemaKind::Schema, '42'],
    'a content object' => [['content' => ['application/json' => ['schema' => ['type' => 'integer']]]], ParameterSchemaKind::Content, '42'],
    // Presence is not the question on either member: a `content` that is not a map of media types is
    // not the content object the note would name, any more than `schema: 'integer'` is a schema. Both
    // are still WRITTEN, which is the half a nullable answer threw away.
    'a content member that is not an object' => [['content' => 'application/json'], ParameterSchemaKind::Malformed, '42'],
    'a type name where a schema belongs' => [['schema' => 'integer'], ParameterSchemaKind::Malformed, '42'],
    'a number where a schema belongs' => [['schema' => 42], ParameterSchemaKind::Malformed, '42'],
    'an explicit null' => [['schema' => null], ParameterSchemaKind::Malformed, '42'],
    'neither member' => [['name' => 'page', 'in' => 'query'], ParameterSchemaKind::Absent, '42'],
    // A schema wins over a content object documented beside it: it is the one this check can read.
    'both, which is a schema' => [
        ['schema' => ['type' => 'string'], 'content' => ['application/json' => []]],
        ParameterSchemaKind::Schema,
        '42',
    ],
    // And a readable content object outranks the member beside it that would not decode: the note a
    // reader gets should name the declaration the document actually made.
    'a content object beside a schema that is not one' => [
        ['schema' => 'integer', 'content' => ['application/json' => []]],
        ParameterSchemaKind::Content,
        '42',
    ],
]);

it('is what a documented parameter answers with, not a schema beside a flag', function (): void {
    $parameter = new ContractParameter('page', 'query', false, ['schema' => ['type' => 'integer']], ['paths', '/a', 'get', 'parameters', '0']);

    expect($parameter->schema())->toBeInstanceOf(ParameterSchema::class)
        ->and($parameter->schema()->kind)->toBe(ParameterSchemaKind::Schema)
        ->and($parameter->schema()->read('42', []))->toBe(42)
        ->and($parameter->schemaSegments())->toBe(['paths', '/a', 'get', 'parameters', '0', 'schema']);
});

/**
 * The keywords are PRIVATE, and this is what says so. They were public, on a docblock claim that the
 * node was "only reachable past a match over the kind" and that PHPStan enforced it — it did not:
 * `ParameterValue::coerce($p->schema()->node, …)` and `$p->schema()->node === null` both analysed
 * clean, which is the pre-fix defect (a null node read as "no schema") reproduced with a green build.
 * PHPStan cannot gate a property on an enum, so the guard is the property being unreachable and this
 * test is what keeps it that way.
 */
it('hands nobody the schema keywords to mistake for the answer', function (): void {
    $reflection = new ReflectionClass(ParameterSchema::class);

    $properties = array_map(
        static fn (ReflectionProperty $property): string => $property->getName(),
        $reflection->getProperties(ReflectionProperty::IS_PUBLIC),
    );

    $methods = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
    );

    sort($methods);

    expect($properties)->toBe(['kind'])
        ->and($methods)->toBe(['of', 'read']);
});

/**
 * The kinds are a closed set and the checker matches over all of them, so a case added here without a
 * sentence written for it is a build failure rather than a note nobody sees. This guards the OTHER
 * direction: a case REMOVED, or renamed, silently shortens every dataset that lists them.
 */
it('names every kind a parameter schema can be', function (): void {
    expect(array_map(static fn (ParameterSchemaKind $kind): string => $kind->name, ParameterSchemaKind::cases()))
        ->toBe(['Schema', 'Content', 'Malformed', 'Absent']);
});
