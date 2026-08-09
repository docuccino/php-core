<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\EnumT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Tests\Support\StubTypeEngine;

/**
 * @param  array<string, mixed>  $schema
 */
function convertWithPolicy(DType $type, RepresentationPolicy $policy): array
{
    $converter = new SchemaConverter(DefaultTypeMappers::all(), new StubTypeEngine, new ComponentRegistry, $policy);

    return $converter->toSchema($type)->schema;
}

it('defaults every policy to behaviour-preserving keywords', function (): void {
    $policy = RepresentationPolicy::fromConfig([]);

    expect($policy->operationId)->toBe('route-name')
        ->and($policy->enumNaming)->toBe('none')
        ->and($policy->nullable)->toBe('type-array');
});

it('reads the nested enums.naming keyword', function (): void {
    $policy = RepresentationPolicy::fromConfig([
        'operation_id' => 'controller-method',
        'nullable' => 'anyof',
        'enums' => ['naming' => 'x-enum-varnames'],
    ]);

    expect($policy->operationId)->toBe('controller-method')
        ->and($policy->nullable)->toBe('anyof')
        ->and($policy->enumNaming)->toBe('x-enum-varnames');
});

it('emits no enum name hints by default', function (): void {
    $schema = convertWithPolicy(new EnumT('App\\Status', ['draft', 'live']), RepresentationPolicy::fromConfig([]));

    expect($schema)->toBe(['type' => 'string', 'enum' => ['draft', 'live']]);
});

it('emits x-enumNames when the policy asks for it', function (): void {
    $schema = convertWithPolicy(new EnumT('App\\Status', ['draft', 'live']), new RepresentationPolicy(enumNaming: 'x-enumNames'));

    expect($schema)->toBe(['type' => 'string', 'enum' => ['draft', 'live'], 'x-enumNames' => ['draft', 'live']]);
});

it('folds null into a type-array by default', function (): void {
    $schema = convertWithPolicy(UnionT::of([ScalarT::string(), new NullT]), RepresentationPolicy::fromConfig([]));

    expect($schema)->toBe(['type' => ['string', 'null']]);
});

it('expresses null as an anyOf branch under the anyof policy', function (): void {
    $schema = convertWithPolicy(UnionT::of([ScalarT::string(), new NullT]), new RepresentationPolicy(nullable: 'anyof'));

    expect($schema)->toBe(['anyOf' => [['type' => 'string'], ['type' => 'null']]]);
});

it('normalises the api_resources wrap config to the resourceWrap keyword', function (mixed $wrap, string $expected): void {
    expect(RepresentationPolicy::fromConfig([], $wrap)->resourceWrap)->toBe($expected);
})->with([
    'unset defers to the resource' => [null, ''],
    'false disables wrapping' => [false, RepresentationPolicy::WRAP_DISABLED],
    'true forces the default key' => [true, 'data'],
    'a string forces that key' => ['records', 'records'],
    'an empty string defers' => ['', ''],
    'a non-string non-bool defers' => [42, ''],
]);
