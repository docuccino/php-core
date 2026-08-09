<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;

it('dedupes a class by its schemaId across references', function (): void {
    $registry = new ComponentRegistry;

    $first = $registry->reference('FormData', ['type' => 'object'], 'App\\Data\\FormData');
    $second = $registry->reference('FormData', ['type' => 'object', 'title' => 'ignored'], 'App\\Data\\FormData');

    expect($first)->toBe(['$ref' => '#/components/schemas/FormData'])
        ->and($second)->toBe(['$ref' => '#/components/schemas/FormData'])
        ->and($registry->schemas())->toHaveCount(1)
        ->and($registry->diagnostics())->toBe([]);
});

it('dedupes structurally-equal anonymous schemas under one name', function (): void {
    $registry = new ComponentRegistry;

    $registry->registerSchema('Thing', ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]]);
    $name = $registry->registerSchema('Thing', ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]]);

    expect($name)->toBe('Thing')
        ->and($registry->schemas())->toHaveCount(1);
});

it('suffixes a genuine name collision and warns', function (): void {
    $registry = new ComponentRegistry;

    $registry->registerSchema('Thing', ['type' => 'object']);
    $name = $registry->registerSchema('Thing', ['type' => 'string']);

    expect($name)->toBe('Thing_2')
        ->and($registry->schemas())->toHaveKeys(['Thing', 'Thing_2']);

    $diagnostics = $registry->diagnostics();
    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->severity)->toBe(Severity::Warning)
        ->and($diagnostics[0]->code)->toBe('components.name-collision');
});
