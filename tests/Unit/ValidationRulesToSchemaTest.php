<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\BuiltIn\DefaultTypeMappers;
use Docuccino\Core\Extensions\Context\RepresentationPolicy;
use Docuccino\Core\Extensions\Contracts\RuleTransformer;
use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Schema\ComponentRegistry;
use Docuccino\Core\Extensions\Schema\SchemaConverter;
use Docuccino\Core\Extensions\Validation\DefaultValidationRulesToSchema;
use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Extensions\Validation\ValidationField;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Core\Inference\NullTypeEngine;

/**
 * The core validation driver is vocabulary-free — it knows no rule names. These tests exercise the
 * chain driver + request schema builder with a couple of tiny fake transformers; the full Laravel
 * rule vocabulary is tested in the FormRequest integration, not here (core has no Illuminate imports).
 */
function ruleContext(RepresentationPolicy $policy = new RepresentationPolicy): SchemaContext
{
    return new SchemaConverter(DefaultTypeMappers::all(), new NullTypeEngine, new ComponentRegistry, $policy);
}

/**
 * A minimal transformer set: `required` marks required, `nullable` marks nullable, `str`/`int` set
 * a scalar type. Enough to drive the builder without any Laravel semantics.
 *
 * @return list<RuleTransformer>
 */
function fakeTransformers(): array
{
    return [
        new class implements RuleTransformer
        {
            public function supports(ValidationRule $rule): bool
            {
                return in_array($rule->name, $this->handledRuleNames(), true);
            }

            public function handledRuleNames(): array
            {
                return ['required', 'nullable', 'str', 'int'];
            }

            public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
            {
                match ($rule->name) {
                    'required' => $field->markRequired(),
                    'nullable' => $field->markNullable(),
                    'str' => $field->setType('string'),
                    default => $field->setType('integer'),
                };
            }
        },
    ];
}

/**
 * @param  array<string, list<string>>  $fields
 */
function fakeRuleSet(array $fields): RuleSet
{
    $out = [];
    foreach ($fields as $field => $names) {
        $out[$field] = array_map(static fn (string $name): ValidationRule => ValidationRule::of($name), $names);
    }

    return new RuleSet($out);
}

it('builds an object schema with required members, applying rules in the given order', function (): void {
    $driver = new DefaultValidationRulesToSchema(fakeTransformers());
    $result = $driver->convert(fakeRuleSet(['title' => ['required', 'str'], 'count' => ['int']]), ruleContext());

    expect($result->schema)->toBe([
        'type' => 'object',
        'properties' => [
            'title' => ['type' => 'string'],
            'count' => ['type' => 'integer'],
        ],
        'required' => ['title'],
    ])->and($result->mediaType)->toBe('application/json');
});

it('nests dot notation and wildcard arrays', function (): void {
    $driver = new DefaultValidationRulesToSchema(fakeTransformers());
    $schema = $driver->convert(fakeRuleSet([
        'author.name' => ['required', 'str'],
        'tags.*' => ['str'],
        'items.*.id' => ['required', 'int'],
    ]), ruleContext())->schema;

    expect($schema['properties']['author'])->toBe([
        'type' => 'object',
        'properties' => ['name' => ['type' => 'string']],
        'required' => ['name'],
    ])->and($schema['properties']['tags'])->toBe([
        'type' => 'array',
        'items' => ['type' => 'string'],
    ])->and($schema['properties']['items'])->toBe([
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer']],
            'required' => ['id'],
        ],
    ]);
});

it('expresses nullable per the representation policy', function (): void {
    $driver = new DefaultValidationRulesToSchema(fakeTransformers());
    $typeArray = $driver->convert(fakeRuleSet(['nick' => ['str', 'nullable']]), ruleContext())->schema;
    $anyOf = $driver->convert(fakeRuleSet(['nick' => ['str', 'nullable']]), ruleContext(new RepresentationPolicy(nullable: 'anyof')))->schema;

    expect($typeArray['properties']['nick'])->toBe(['type' => ['string', 'null']])
        ->and($anyOf['properties']['nick'])->toBe(['anyOf' => [['type' => 'string'], ['type' => 'null']]]);
});

it('leaves an unhandled rule permissive and raises an info diagnostic', function (): void {
    $driver = new DefaultValidationRulesToSchema(fakeTransformers());
    $result = $driver->convert(fakeRuleSet(['token' => ['str', 'mystery']]), ruleContext());

    expect($result->schema['properties']['token'])->toBe(['type' => 'string'])
        ->and($result->diagnostics)->toHaveCount(1)
        ->and($result->diagnostics[0]->code)->toBe('validation.rule-unhandled')
        ->and($result->diagnostics[0]->message)->toContain('mystery');
});

it('lets an earlier transformer intercept a rule ahead of later ones', function (): void {
    $custom = new class implements RuleTransformer
    {
        public function supports(ValidationRule $rule): bool
        {
            return in_array($rule->name, $this->handledRuleNames(), true);
        }

        public function handledRuleNames(): array
        {
            return ['mystery'];
        }

        public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
        {
            $field->set('pattern', '^x');
        }
    };

    $driver = new DefaultValidationRulesToSchema([$custom, ...fakeTransformers()]);
    $result = $driver->convert(fakeRuleSet(['token' => ['str', 'mystery']]), ruleContext());

    expect($result->schema['properties']['token'])->toBe(['type' => 'string', 'pattern' => '^x'])
        ->and($result->diagnostics)->toBe([]);
});

it('returns an empty schema for an empty rule set', function (): void {
    $result = (new DefaultValidationRulesToSchema(fakeTransformers()))->convert(new RuleSet, ruleContext());

    expect($result->isEmpty())->toBeTrue()
        ->and($result->mediaType)->toBe('application/json');
});
