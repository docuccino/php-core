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
 * a scalar type, `map` declares open object values and `closed` refuses them. Enough to drive the
 * builder without any Laravel semantics.
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
                return ['required', 'nullable', 'str', 'int', 'map', 'closed'];
            }

            public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
            {
                match ($rule->name) {
                    'required' => $field->markRequired(),
                    'nullable' => $field->markNullable(),
                    'str' => $field->setType('string'),
                    'map' => $this->map($field),
                    'closed' => $this->closed($field),
                    default => $field->setType('integer'),
                };
            }

            private function map(ValidationField $field): void
            {
                $field->setType('object');
                $field->set('additionalProperties', []);
            }

            private function closed(ValidationField $field): void
            {
                $field->setType('object');
                $field->set('additionalProperties', false);
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

it('publishes a `*` child as the value schema of a node that declares additionalProperties', function (): void {
    // `additionalProperties` and `items` are the SAME slot for two different containers — an object's
    // values and an array's items — and mutually exclusive on one schema. A `*` segment names whichever
    // the node is, so the node's own declaration decides which slot gets filled; emitting both would
    // leave one of them inert.
    $driver = new DefaultValidationRulesToSchema(fakeTransformers());
    $schema = $driver->convert(fakeRuleSet([
        'settings' => ['map'],
        'settings.*' => ['str'],
        'tags' => ['nullable'],
        'tags.*' => ['str'],
    ]), ruleContext())->schema;

    expect($schema['properties']['settings'])->toBe([
        'type' => 'object',
        'additionalProperties' => ['type' => 'string'],
    ])->and($schema['properties']['tags'])->toBe([
        'type' => ['array', 'null'],
        'items' => ['type' => 'string'],
    ]);
});

it('leaves a closed object closed when a `*` child names its values', function (): void {
    // `additionalProperties: false` is a constraint of its own, not an empty slot: filling it with the
    // child's schema would publish a document more permissive than the one the writer declared.
    $driver = new DefaultValidationRulesToSchema(fakeTransformers());
    $schema = $driver->convert(fakeRuleSet([
        'settings' => ['closed'],
        'settings.*' => ['str'],
    ]), ruleContext())->schema;

    expect($schema['properties']['settings'])->toBe(['type' => 'object', 'additionalProperties' => false]);
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

it('lets a later transformer drop a keyword an earlier one left', function (): void {
    // The replacement case: a rule that supersedes an earlier one's claim rather than adding to it, and
    // would otherwise leave a keyword standing against a type it no longer belongs to.
    $replacing = new class implements RuleTransformer
    {
        public function supports(ValidationRule $rule): bool
        {
            return in_array($rule->name, $this->handledRuleNames(), true);
        }

        public function handledRuleNames(): array
        {
            return ['really_an_int'];
        }

        public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
        {
            expect($field->has('pattern'))->toBeTrue();

            $field->setType('integer');
            $field->remove('pattern');
            // Removing what was never there is a no-op, not an error.
            $field->remove('pattern');
            $field->remove('never-set');
        }
    };

    $stating = new class implements RuleTransformer
    {
        public function supports(ValidationRule $rule): bool
        {
            return in_array($rule->name, $this->handledRuleNames(), true);
        }

        public function handledRuleNames(): array
        {
            return ['patterned'];
        }

        public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
        {
            $field->set('pattern', '^x');
        }
    };

    $driver = new DefaultValidationRulesToSchema([$stating, $replacing, ...fakeTransformers()]);
    $result = $driver->convert(fakeRuleSet(['token' => ['str', 'patterned', 'really_an_int']]), ruleContext());

    expect($result->schema['properties']['token'])->toBe(['type' => 'integer'])
        ->and($result->diagnostics)->toBe([]);
});

it('refuses a guessing rule the keyword a later one withdrew', function (): void {
    // A rule that drops a keyword has decided no keyword describes the value — for the field, not just for
    // its own moment in the chain. So a coarser rule filling the gap back in is told no, while a rule
    // STATING one on the author's behalf still gets to.
    $transformer = new class implements RuleTransformer
    {
        public function supports(ValidationRule $rule): bool
        {
            return in_array($rule->name, $this->handledRuleNames(), true);
        }

        public function handledRuleNames(): array
        {
            return ['formatted', 'withdraws', 'guesses', 'states'];
        }

        public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
        {
            match ($rule->name) {
                'formatted' => $field->set('format', 'date'),
                'withdraws' => $field->remove('format'),
                'guesses' => $field->mayClaim('format') ? $field->set('format', 'date') : null,
                default => $field->set('format', 'iban'),
            };
        }
    };

    $driver = new DefaultValidationRulesToSchema([$transformer, ...fakeTransformers()]);
    $result = $driver->convert(fakeRuleSet([
        'withdrawn' => ['str', 'formatted', 'withdraws', 'guesses'],
        'untouched' => ['str', 'guesses'],
        'stated' => ['str', 'formatted', 'withdraws', 'states'],
    ]), ruleContext());

    expect($result->schema['properties']['withdrawn'])->toBe(['type' => 'string'])
        ->and($result->schema['properties']['untouched'])->toBe(['type' => 'string', 'format' => 'date', 'example' => '2024-01-01'])
        ->and($result->schema['properties']['stated'])->toBe(['type' => 'string', 'format' => 'iban']);
});

it('returns an empty schema for an empty rule set', function (): void {
    $result = (new DefaultValidationRulesToSchema(fakeTransformers()))->convert(new RuleSet, ruleContext());

    expect($result->isEmpty())->toBeTrue()
        ->and($result->mediaType)->toBe('application/json');
});
