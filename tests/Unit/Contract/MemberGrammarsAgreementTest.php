<?php

declare(strict_types=1);

use Docuccino\Core\Contract\SchemaMembers;
use Docuccino\Core\Document\DocumentMembers;

/*
 * Two grammars read a Schema Object's members: DocumentMembers for the walks over a whole document, and
 * SchemaMembers for the walks over what a check hands the validator. Where both read a schema's own
 * keywords they state one fact twice, so each is held here to a rule written from the specifications
 * rather than to the other.
 *
 * JSON Schema 2020-12 gives the keywords whose value is an instance (Validation §6.1.2 `enum`, §6.1.3
 * `const`, §9.2 `default`, §9.5 `examples`), and OpenAPI adds the Schema Object's `example` and its `x-`
 * extensions. It gives the maps whose keys are whatever the author called them (Core §10.3.2.1
 * `properties`, §10.3.2.2 `patternProperties`, §8.2.4 `$defs` with draft-07's `definitions`, §10.2.2.4
 * `dependentSchemas`), and the keywords that hold a schema or a list of them.
 */

dataset('keywords holding an instance', [
    'const' => ['const', 'x'],
    'enum' => ['enum', ['a', 'b']],
    'default' => ['default', ['x' => 1]],
    'examples' => ['examples', [['x' => 1]]],
    'example' => ['example', ['x' => 1]],
    'an extension' => ['x-anything', ['x' => 1]],
]);

it('reads a keyword whose value is an instance as data in both grammars', function (string $keyword, mixed $value): void {
    expect(DocumentMembers::holdsData($keyword, $value, null))->toBeTrue()
        ->and(SchemaMembers::member($keyword, SchemaMembers::SCHEMA))->toBe(SchemaMembers::DATA);
})->with('keywords holding an instance');

dataset('keywords opening a map of names', ['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas']);

it('reads a keyword whose keys are names as a map of names in both grammars', function (string $keyword): void {
    expect(DocumentMembers::holdsData($keyword, [], null))->toBeFalse()
        ->and(DocumentMembers::nameMap($keyword, null))->toBe($keyword)
        ->and(SchemaMembers::member($keyword, SchemaMembers::SCHEMA))->toBe(SchemaMembers::NAMES);
})->with('keywords opening a map of names');

it('reads a name inside such a map as the name it is in both grammars, however it is spelled', function (string $map, string $name): void {
    expect(DocumentMembers::holdsData($name, ['type' => 'string'], $map))->toBeFalse()
        ->and(DocumentMembers::nameMap($name, $map))->toBeNull()
        ->and(SchemaMembers::member($name, SchemaMembers::NAMES))->toBe(SchemaMembers::SCHEMA);
})->with('keywords opening a map of names')->with(['default', 'const', 'enum', 'example', 'examples', 'properties', 'x-anything']);

it('reads a keyword holding schemas as neither data nor a map of names in both grammars', function (string $keyword): void {
    expect(DocumentMembers::holdsData($keyword, ['type' => 'string'], null))->toBeFalse()
        ->and(DocumentMembers::nameMap($keyword, null))->toBeNull()
        ->and(SchemaMembers::member($keyword, SchemaMembers::SCHEMA))->toBeIn([SchemaMembers::SCHEMA, SchemaMembers::LIST]);
})->with([
    'items', 'prefixItems', 'contains', 'additionalProperties', 'propertyNames', 'unevaluatedItems',
    'unevaluatedProperties', 'contentSchema', 'not', 'if', 'then', 'else', 'allOf', 'anyOf', 'oneOf',
]);

it('reads dependentRequired as property names in both grammars, so nothing under it is a keyword', function (): void {
    // Validation §6.5.4: each member is a property name holding a list of property names.
    expect(DocumentMembers::nameMap('dependentRequired', null))->toBe('dependentRequired')
        ->and(SchemaMembers::member('dependentRequired', SchemaMembers::SCHEMA))->toBe(SchemaMembers::DATA)
        ->and(SchemaMembers::isReference('$ref', SchemaMembers::DATA))->toBeFalse();
});
