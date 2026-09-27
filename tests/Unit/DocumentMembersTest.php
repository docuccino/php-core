<?php

declare(strict_types=1);

use Docuccino\Core\Document\DocumentMembers;

/*
 * The one grammar every whole-document walk reads a member by: data, a map of names, or a node of
 * keywords. Every entry of each table has a row, and the OpenAPI half is held against the vendored
 * meta-schemas, which are what say where a key is a name.
 */

it('reads a literal as data at a keyword position and as a name inside a map of names', function (string $literal): void {
    expect(DocumentMembers::holdsData($literal, ['anyOf' => []], null))->toBeTrue()
        ->and(DocumentMembers::holdsData($literal, ['anyOf' => []], 'properties'))->toBeFalse()
        ->and(DocumentMembers::holdsData($literal, ['anyOf' => []], 'responses'))->toBeFalse();
})->with(['example', 'default', 'const', 'enum', 'value', 'dataValue']);

it('tells a list of literals from a map of Example Objects under examples', function (): void {
    expect(DocumentMembers::holdsData('examples', [['a' => 1]], null))->toBeTrue()
        ->and(DocumentMembers::holdsData('examples', [], null))->toBeTrue()
        ->and(DocumentMembers::holdsData('examples', ['one' => ['value' => 1]], null))->toBeFalse()
        ->and(DocumentMembers::holdsData('examples', new stdClass, null))->toBeFalse();
});

it('reads an x- member as an extension only where the Object admits one', function (?string $inNameMap, bool $data): void {
    expect(DocumentMembers::holdsData('x-anything', [], $inNameMap))->toBe($data);
})->with([
    'a keyword position' => [null, true],
    'paths' => ['paths', true],
    'responses' => ['responses', true],
    'properties' => ['properties', false],
    'headers' => ['headers', false],
    'content' => ['content', false],
    'schemas' => ['schemas', false],
]);

it('reads anything else as a node', function (string $key): void {
    expect(DocumentMembers::holdsData($key, [], null))->toBeFalse();
})->with(['schema', 'items', 'anyOf', 'properties', 'responses', 'unknown']);

it('opens a map of names at every member whose keys are names', function (string $key): void {
    expect(DocumentMembers::nameMap($key, null))->toBe($key)
        // Inside a map, the same key is a name, and what it holds is a node.
        ->and(DocumentMembers::nameMap($key, 'properties'))->toBeNull();
})->with([
    'additionalOperations', 'callbacks', 'content', 'encoding', 'examples', 'headers', 'links', 'mapping',
    'mediaTypes', 'parameters', 'paths', 'pathItems', 'requestBodies', 'responses', 'schemas', 'scopes',
    'securitySchemes', 'variables', 'webhooks',
    // JSON Schema's own, read off the keyword table.
    'properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas', 'dependentRequired',
]);

it('opens none at a member whose keys are keywords', function (string $key): void {
    expect(DocumentMembers::nameMap($key, null))->toBeNull();
})->with(['schema', 'items', 'anyOf', 'allOf', 'components', 'info', 'discriminator', 'unknown']);

/**
 * Every OpenAPI member the vendored meta-schemas describe as a map, and whether its Object admits `x-`
 * members: a map is an object with no fixed fields and either `additionalProperties` or a
 * `patternProperties` entry that is not the extension pattern.
 *
 * @return array{maps: list<string>, extensible: list<string>}
 */
function metaSchemaNameMaps(): array
{
    $maps = [];
    $extensible = [];

    foreach (['3.0', '3.1', '3.2'] as $version) {
        $schema = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/resources/openapi/openapi-v'.$version.'.schema.json'), true, flags: JSON_THROW_ON_ERROR);
        $defs = $schema['$defs'] ?? $schema['definitions'];

        $resolve = static function (mixed $node) use ($defs): mixed {
            $ref = is_array($node) ? ($node['$ref'] ?? null) : null;

            return is_string($ref) && str_starts_with($ref, '#/') ? ($defs[basename($ref)] ?? $node) : $node;
        };

        foreach ([$schema, ...array_values($defs)] as $owner) {
            foreach ($owner['properties'] ?? [] as $name => $member) {
                $member = $resolve($member);
                if (! is_array($member)) {
                    continue;
                }

                // Fixed fields make an Object, not a map — bar the Responses Object's `default`, which is a
                // status code spelled as a word.
                $patterns = array_keys($member['patternProperties'] ?? []);
                $additional = $member['additionalProperties'] ?? false;
                $fixed = array_diff(array_keys($member['properties'] ?? []), ['default']);
                if ($fixed !== [] || ($additional === false && array_diff($patterns, ['^x-']) === [])) {
                    continue;
                }

                $maps[] = (string) $name;
                if (in_array('^x-', $patterns, true) || ($member['$ref'] ?? null) === '#/$defs/specification-extensions') {
                    $extensible[] = (string) $name;
                }
            }
        }
    }

    $maps = array_values(array_unique($maps));
    $extensible = array_values(array_unique($extensible));
    sort($maps);
    sort($extensible);

    return ['maps' => $maps, 'extensible' => $extensible];
}

it('opens a map of names wherever the OpenAPI meta-schemas say the keys are names', function (): void {
    $derived = metaSchemaNameMaps();

    // A scan that stopped seeing its shapes would pass everything below, so it owes a plausible count.
    expect(count($derived['maps']))->toBeGreaterThanOrEqual(15)
        ->and($derived['extensible'])->toContain('paths', 'responses');

    foreach ($derived['maps'] as $key) {
        expect(DocumentMembers::nameMap($key, null))->toBe($key, $key);
    }

    foreach ($derived['maps'] as $key) {
        expect(DocumentMembers::holdsData('x-a', [], $key))->toBe(in_array($key, $derived['extensible'], true), $key);
    }
});
