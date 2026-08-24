<?php

declare(strict_types=1);

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
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
use Docuccino\Core\Extensions\Validation\ValidationSchema;
use Docuccino\Core\Inference\NullTypeEngine;
use Docuccino\Core\Support\FormatSamples;
use Opis\JsonSchema\Validator as OpisValidator;

/**
 * The synthesized property `example`, driven through the public chain the way every recovery
 * integration reaches it. Core knows no rule names, so the transformer here speaks in KEYWORDS: a
 * `kw` rule writes one, a `propose` rule offers a value only it could know. That is exactly the split
 * the Laravel vocabulary uses — its own table is pinned in ValidationVocabularyTest.
 */
function exampleContext(RepresentationPolicy $policy = new RepresentationPolicy): SchemaContext
{
    return new SchemaConverter(DefaultTypeMappers::all(), new NullTypeEngine, new ComponentRegistry, $policy);
}

/**
 * Convert one field described as keyword writes and proposals, and return the property schema.
 *
 * `['kw', 'type', 'string']` sets a keyword; `['propose', <json>]` proposes an example value, with
 * `null` standing for the suppression form.
 *
 * @param  list<array<int, string|null>>  $steps
 * @return array<string, mixed>
 */
function exampleProperty(array $steps, RepresentationPolicy $policy = new RepresentationPolicy): array
{
    $schema = exampleConversion($steps, $policy)->schema;

    $property = $schema['properties']['f'] ?? [];

    return is_array($property) ? $property : [];
}

/**
 * The same conversion, unwrapped — the whole {@see ValidationSchema}, so a test can read the
 * diagnostics synthesis raised alongside the schema it produced.
 *
 * @param  list<array<int, string|null>>  $steps
 */
function exampleConversion(array $steps, RepresentationPolicy $policy = new RepresentationPolicy): ValidationSchema
{
    $transformer = new class implements RuleTransformer
    {
        public function supports(ValidationRule $rule): bool
        {
            return in_array($rule->name, $this->handledRuleNames(), true);
        }

        public function handledRuleNames(): array
        {
            return ['kw', 'propose'];
        }

        public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
        {
            if ($rule->name === 'propose') {
                $raw = $rule->parameter();
                $field->proposeExample($raw === null ? null : json_decode($raw, true));

                return;
            }

            $field->set((string) $rule->parameter(0), json_decode((string) $rule->parameter(1), true));
        }
    };

    $rules = array_map(
        static fn (array $step): ValidationRule => ValidationRule::of(
            (string) $step[0],
            array_map(static fn (?string $p): string => (string) $p, array_slice($step, 1)),
        ),
        $steps,
    );

    // A proposal's null form has to survive the string-parameter journey, so it travels as no parameter.
    $rules = array_map(
        static fn (ValidationRule $rule, int $i): ValidationRule => $steps[$i][0] === 'propose' && $steps[$i][1] === null
            ? ValidationRule::of('propose')
            : $rule,
        $rules,
        array_keys($rules),
    );

    return (new DefaultValidationRulesToSchema([$transformer]))
        ->convert(new RuleSet(['f' => $rules]), exampleContext($policy));
}

/** `['type', '"string"']` shorthand for one keyword write. */
function kw(string $keyword, string $json): array
{
    return ['kw', $keyword, $json];
}

/**
 * The keyword → example table, plus every way it declines. A row whose expectation is null asserts
 * NO example was published, which is the degradation half of the standard.
 */
it('derives an example from what the keywords pin, and nothing where they pin only a type', function (array $steps, mixed $expected): void {
    $property = exampleProperty($steps);

    if ($expected === null) {
        expect($property)->not->toHaveKey('example');

        return;
    }

    expect($property['example'] ?? null)->toBe($expected);
})->with([
    // Booleans: the whole domain is two values, so `true` says as much as anything can.
    'boolean' => [[kw('type', '"boolean"')], true],

    // An enum's first member — a list's order is authored, and every other reader shows the same one.
    'enum (strings)' => [[kw('type', '"string"'), kw('enum', '["draft","published"]')], 'draft'],
    'enum (integers)' => [[kw('type', '"integer"'), kw('enum', '[3,4]')], 3],
    'enum beats the format sample' => [[kw('type', '"string"'), kw('format', '"email"'), kw('enum', '["a@b.test"]')], 'a@b.test'],

    // Formats: the sample the table holds.
    'format email' => [[kw('type', '"string"'), kw('format', '"email"')], 'user@example.com'],
    'format date' => [[kw('type', '"string"'), kw('format', '"date"')], '2024-01-01'],
    'format uuid' => [[kw('type', '"string"'), kw('format', '"uuid"')], '3fa85f64-5717-4562-b3fc-2c963f66afa6'],

    // Numeric bounds: the lowest legal value at or above the seed.
    'minimum' => [[kw('type', '"integer"'), kw('minimum', '18')], 18],
    'maximum below the seed' => [[kw('type', '"integer"'), kw('maximum', '0')], 0],
    'maximum above the seed' => [[kw('type', '"integer"'), kw('maximum', '99')], 1],
    'exclusiveMinimum' => [[kw('type', '"integer"'), kw('exclusiveMinimum', '5')], 6],
    'exclusiveMaximum' => [[kw('type', '"integer"'), kw('exclusiveMaximum', '1')], 0],
    'multipleOf' => [[kw('type', '"integer"'), kw('multipleOf', '5')], 5],
    'float minimum keeps its decimal' => [[kw('type', '"number"'), kw('minimum', '2.5')], 2.5],
    'integer type never publishes a float' => [[kw('type', '"integer"'), kw('multipleOf', '2'), kw('minimum', '3')], 4],

    // Lengths: a prefix of the filler at a length the bounds allow.
    'maxLength above the preferred length' => [[kw('type', '"string"'), kw('maxLength', '255')], 'example'],
    'maxLength below it truncates' => [[kw('type', '"string"'), kw('maxLength', '5')], 'examp'],
    'minLength above it extends' => [[kw('type', '"string"'), kw('minLength', '12')], 'example-valu'],
    'size (both bounds equal)' => [[kw('type', '"string"'), kw('minLength', '3'), kw('maxLength', '3')], 'exa'],

    // A pattern counts as pinning something: the filler prefix either matches it or is refused.
    'pattern the filler matches' => [[kw('type', '"string"'), kw('pattern', '"^[a-z-]+$"')], 'example'],
    'pattern the filler does not match' => [[kw('type', '"string"'), kw('pattern', '"^\\\\d{5}$"')], null],

    // Nothing beyond the base type: `type` already tells a generator that much.
    'bare string' => [[kw('type', '"string"')], null],
    'bare integer' => [[kw('type', '"integer"')], null],
    'bare number' => [[kw('type', '"number"')], null],
    'unknown format' => [[kw('type', '"string"'), kw('format', '"iban"')], null],
    'no type at all' => [[kw('description', '"Just prose."')], null],

    // Shapes no scalar illustrates, or that already state their value.
    'const states the value itself' => [[kw('type', '"boolean"'), kw('const', 'true')], null],
    'binary is an upload, not an illustration' => [[kw('type', '"string"'), kw('format', '"binary"'), kw('maxLength', '32')], null],
    'array' => [[kw('type', '"array"'), kw('minItems', '1')], null],
    'object' => [[kw('type', '"object"')], null],

    // Contradictions: no value satisfies them, so none is published.
    'bounds that cross' => [[kw('type', '"integer"'), kw('minimum', '10'), kw('maximum', '5')], null],
    'lengths that cross' => [[kw('type', '"string"'), kw('minLength', '9'), kw('maxLength', '4')], null],
    'length floor above the cap' => [[kw('type', '"string"'), kw('minLength', '200')], null],
    'multipleOf with no room under the ceiling' => [[kw('type', '"integer"'), kw('multipleOf', '10'), kw('maximum', '4')], null],
    'enum member the other keywords refuse' => [[kw('type', '"integer"'), kw('enum', '["nine"]')], null],
    'format sample the length bound refuses' => [[kw('type', '"string"'), kw('format', '"email"'), kw('maxLength', '4')], null],
]);

it('publishes a proposal the schema agrees with, and refuses one it does not', function (): void {
    // A value only the rule could know — a wire date format the schema cannot carry.
    expect(exampleProperty([kw('type', '"string"'), ['propose', '"01/01/2024"']])['example'] ?? null)->toBe('01/01/2024');

    // The same proposal against a `format` that contradicts it: the schema is what a client validates
    // against, so the proposal is dropped rather than published beside a keyword it fails.
    expect(exampleProperty([kw('type', '"string"'), kw('format', '"date"'), ['propose', '"01/01/2024"']]))
        ->not->toHaveKey('example');

    // A proposal beats the derivation, whichever order the rules arrive in.
    expect(exampleProperty([['propose', '"UTC"'], kw('type', '"string"'), kw('maxLength', '64')])['example'] ?? null)->toBe('UTC');
});

it('lets a rule withdraw the example entirely, and keeps the withdrawal final', function (): void {
    // A file upload, a decimal-places constraint: the rule says no value here is truthful.
    expect(exampleProperty([kw('type', '"number"'), ['propose', null]]))->not->toHaveKey('example');

    // Final: a later proposal cannot revive it, in either order.
    expect(exampleProperty([kw('type', '"string"'), ['propose', null], ['propose', '"x"']]))->not->toHaveKey('example');
    expect(exampleProperty([kw('type', '"string"'), ['propose', '"x"'], ['propose', null]]))->not->toHaveKey('example');
});

it('never overwrites an example the rules stated outright', function (): void {
    // An author's `example` keyword is the contract as they wrote it — neither the format's sample nor
    // a proposal displaces it, and it is not held to the schema here (the example audit does that).
    expect(exampleProperty([kw('type', '"string"'), kw('format', '"email"'), kw('example', '"ops@example.test"')])['example'] ?? null)
        ->toBe('ops@example.test');
    expect(exampleProperty([kw('type', '"string"'), kw('example', '"mine"'), ['propose', '"theirs"']])['example'] ?? null)
        ->toBe('mine');
});

it('synthesizes into array items and nested objects, never onto the container itself', function (): void {
    $rules = new RuleSet([
        'tags.*' => [ValidationRule::of('kw', ['type', '"string"']), ValidationRule::of('kw', ['format', '"email"'])],
        'author.name' => [ValidationRule::of('kw', ['type', '"string"']), ValidationRule::of('kw', ['maxLength', '40'])],
    ]);

    $transformer = new class implements RuleTransformer
    {
        public function supports(ValidationRule $rule): bool
        {
            return $rule->name === 'kw';
        }

        public function handledRuleNames(): array
        {
            return ['kw'];
        }

        public function apply(ValidationRule $rule, ValidationField $field, SchemaContext $context): void
        {
            $field->set((string) $rule->parameter(0), json_decode((string) $rule->parameter(1), true));
        }
    };

    $schema = (new DefaultValidationRulesToSchema([$transformer]))->convert($rules, exampleContext())->schema;

    expect($schema['properties']['tags'])->toBe([
        'type' => 'array',
        'items' => ['type' => 'string', 'format' => 'email', 'example' => 'user@example.com'],
    ])->and($schema['properties']['author'])->toBe([
        'type' => 'object',
        'properties' => ['name' => ['type' => 'string', 'maxLength' => 40, 'example' => 'example']],
    ]);
});

/**
 * Determinism is the product feature the whole synthesis rides on: two conversions of one rule set
 * must be byte-identical, and neither the process timezone nor the locale may reach a value.
 */
it('produces byte-identical bytes across builds, timezones and locales', function (): void {
    $steps = [
        kw('type', '"string"'), kw('format', '"date-time"'), kw('minLength', '4'),
    ];

    $timezone = date_default_timezone_get();
    $locale = setlocale(LC_ALL, '0');

    try {
        date_default_timezone_set('UTC');
        setlocale(LC_ALL, 'C');
        $first = json_encode(exampleProperty($steps));

        date_default_timezone_set('Pacific/Kiritimati');
        setlocale(LC_ALL, 'C.UTF-8', 'en_US.UTF-8', 'C');
        $second = json_encode(exampleProperty($steps));
    } finally {
        date_default_timezone_set($timezone);
        if (is_string($locale)) {
            setlocale(LC_ALL, $locale);
        }
    }

    expect($first)->toBe($second)
        ->and($first)->toContain('2024-01-01T00:00:00Z');
});

/**
 * The format table is a mapping table, so every entry gets a row: the sample exists and the format it
 * is filed under accepts it. Unknown formats degrade to null, which is what stops a made-up sample
 * reaching the document.
 */
it('answers for every format it lists with a value that format accepts', function (string $format): void {
    $sample = FormatSamples::for($format);

    expect($sample)->toBeString();

    $schema = json_decode((string) json_encode(['type' => 'string', 'format' => $format]));

    expect((new OpisValidator)->validate($sample, $schema)->isValid())->toBeTrue();
})->with(array_map(static fn (string $f): array => [$f], FormatSamples::formats()));

it('lists exactly the formats it is expected to answer for, and nothing for any other', function (): void {
    // The dataset above only proves the rows the table HAS; this fails when one is added silently.
    expect(FormatSamples::formats())->toBe([
        'date-time', 'date', 'time', 'duration', 'email', 'idn-email', 'uuid', 'ulid',
        'uri', 'uri-reference', 'url', 'hostname', 'ip', 'ipv4', 'ipv6', 'byte', 'binary', 'password',
    ]);

    expect(FormatSamples::for('iban'))->toBeNull()
        ->and(FormatSamples::for(''))->toBeNull();
});

/**
 * The merge is per format, at the one lookup, so the table stays the single answer: an override answers
 * for the format it names and for nothing else, and a format the table doesn't know can be added.
 */
it('merges configured samples over the table, format by format', function (array $overrides, string $format, ?string $expected): void {
    expect(FormatSamples::for($format, $overrides))->toBe($expected);
})->with([
    'no overrides at all' => [[], 'email', 'user@example.com'],
    'the format overridden' => [['email' => 'jane@example.com'], 'email', 'jane@example.com'],
    'a sibling overridden leaves this one alone' => [['hostname' => 'api.example.net'], 'email', 'user@example.com'],
    'the sibling itself' => [['hostname' => 'api.example.net'], 'hostname', 'api.example.net'],
    'a format the table does not know can be added' => [['iban' => 'GB33BUKB20201555555555'], 'iban', 'GB33BUKB20201555555555'],
    'an unrelated override answers nothing for an unknown format' => [['email' => 'jane@example.com'], 'iban', null],
    'an override never invents an answer for the empty format' => [['email' => 'jane@example.com'], '', null],
]);

/**
 * `representation.examples.formats` reaches synthesis through the representation policy, the same route
 * `enums.naming` travels. Overriding one format moves that format's example and nothing else.
 */
it('illustrates a format with the sample the document configured', function (): void {
    $policy = RepresentationPolicy::fromConfig(['examples' => ['formats' => ['email' => 'jane@example.com']]]);

    expect(exampleProperty([kw('type', '"string"'), kw('format', '"email"')], $policy)['example'] ?? null)
        ->toBe('jane@example.com')
        // A format nobody overrode keeps its documentation-reserved constant.
        ->and(exampleProperty([kw('type', '"string"'), kw('format', '"uuid"')], $policy)['example'] ?? null)
        ->toBe('3fa85f64-5717-4562-b3fc-2c963f66afa6');
});

it('adds a sample for a format the built-in table has none for', function (): void {
    $policy = RepresentationPolicy::fromConfig(['examples' => ['formats' => ['iban' => 'GB33BUKB20201555555555']]]);

    expect(exampleProperty([kw('type', '"string"'), kw('format', '"iban"')], $policy)['example'] ?? null)
        ->toBe('GB33BUKB20201555555555');
});

it('publishes the same property with no configuration and with an empty one', function (array $configured): void {
    $steps = [kw('type', '"string"'), kw('format', '"email"')];
    $policy = RepresentationPolicy::fromConfig($configured);

    expect(exampleProperty($steps, $policy))->toBe(exampleProperty($steps))
        ->and(exampleConversion($steps, $policy)->diagnostics)->toBe([]);
})->with([
    'nothing configured' => [[]],
    'an empty examples bag' => [['examples' => []]],
    'an empty formats map' => [['examples' => ['formats' => []]]],
    'a format nothing uses — examples are demand-driven, so this is not an error' => [['examples' => ['formats' => ['iban' => 'GB33BUKB20201555555555']]]],
    // Restating the built-in value is not an override at all, so it cannot be rejected either.
    'the built-in value restated' => [['examples' => ['formats' => ['email' => 'user@example.com']]]],
    'a non-array where the map should be' => [['examples' => ['formats' => 'user@example.com']]],
    'a non-string sample, which the adapter reports instead' => [['examples' => ['formats' => ['email' => ['nope']]]]],
]);

/**
 * The honest half, and the reason this is a feature rather than a decoration: a configured sample is
 * held to the same rule as a derived one — validated against the field's FINISHED keywords — and one
 * that fails is named and replaced by the built-in sample, never dropped in silence. The message names
 * the format, the value and the keyword, so the reader can fix the config without guessing which field
 * refused it.
 */
it('falls back to the built-in sample and says so when a configured one fails the field rules', function (): void {
    $policy = RepresentationPolicy::fromConfig(['examples' => ['formats' => ['email' => 'jane.doe+billing@example.com']]]);
    $conversion = exampleConversion([kw('type', '"string"'), kw('format', '"email"'), kw('maxLength', '20')], $policy);

    $property = $conversion->schema['properties']['f'] ?? [];

    expect($property['example'] ?? null)->toBe('user@example.com')
        ->and($conversion->diagnostics)->toHaveCount(1)
        ->and($conversion->diagnostics[0]->severity)->toBe(Severity::Warning)
        ->and($conversion->diagnostics[0]->code)->toBe('config.format-sample-rejected')
        ->and($conversion->diagnostics[0]->message)->toBe(
            'The example configured for format "email" ("jane.doe+billing@example.com") does not satisfy the rules on field "f": maxLength. The built-in sample ("user@example.com") is published instead.',
        )
        ->and($conversion->diagnostics[0]->help)->toBe(
            'Set representation.examples.formats.email to a value every field carrying that format accepts, or drop the key.',
        );
});

it('publishes no example where the rejected format had no built-in sample to fall back on', function (): void {
    $policy = RepresentationPolicy::fromConfig(['examples' => ['formats' => ['iban' => 'GB33BUKB20201555555555']]]);
    // A `pattern` is what pins this field, so no length bound earns a filler prefix either: nothing is
    // published, which is the honest answer when both the configured sample and the fallback are gone.
    $conversion = exampleConversion([kw('type', '"string"'), kw('format', '"iban"'), kw('pattern', '"^[0-9]+$"')], $policy);

    expect($conversion->schema['properties']['f'] ?? [])->not->toHaveKey('example')
        ->and($conversion->diagnostics)->toHaveCount(1)
        ->and($conversion->diagnostics[0]->message)->toBe(
            'The example configured for format "iban" ("GB33BUKB20201555555555") does not satisfy the rules on field "f": pattern. The format has no built-in sample to fall back on, so the field publishes none.',
        );
});

it('names the keyword that refused a configured sample', function (string $keyword, string $json, string $sample, string $reported): void {
    $policy = RepresentationPolicy::fromConfig(['examples' => ['formats' => ['email' => $sample]]]);
    $conversion = exampleConversion([kw('type', '"string"'), kw('format', '"email"'), kw($keyword, $json)], $policy);

    expect($conversion->diagnostics)->toHaveCount(1)
        ->and($conversion->diagnostics[0]->message)->toContain(': '.$reported.'.');
})->with([
    'a length ceiling' => ['maxLength', '20', 'jane.doe+billing@example.com', 'maxLength'],
    'a length floor' => ['minLength', '40', 'jane@example.com', 'minLength'],
    'a pattern' => ['pattern', '"^[a-z]+$"', 'jane@example.com', 'pattern'],
    'the format itself' => ['title', '"Contact"', 'not-an-email', 'format'],
]);

/**
 * A field that never asked for the format is untouched, and a rejection on one field does not withdraw
 * the sample from another the rules DO accept — the check is per field, on that field's keywords.
 */
it('rejects a configured sample only on the fields whose rules refuse it', function (): void {
    $policy = RepresentationPolicy::fromConfig(['examples' => ['formats' => ['email' => 'jane@example.com']]]);

    $roomy = exampleConversion([kw('type', '"string"'), kw('format', '"email"'), kw('maxLength', '40')], $policy);
    $tight = exampleConversion([kw('type', '"string"'), kw('format', '"email"'), kw('maxLength', '8')], $policy);

    expect($roomy->schema['properties']['f']['example'] ?? null)->toBe('jane@example.com')
        ->and($roomy->diagnostics)->toBe([])
        ->and($tight->schema['properties']['f'] ?? [])->not->toHaveKey('example')
        ->and($tight->diagnostics)->toHaveCount(1);
});

/**
 * Determinism, for the configured value as much as for a derived one: the same config produces the same
 * bytes and the same diagnostic every run, and the order the formats were written in is not an input.
 */
it('publishes a configured sample and its rejection byte-identically across runs', function (): void {
    $configured = ['examples' => ['formats' => ['email' => 'jane@example.com', 'hostname' => 'api.example.net']]];
    $reversed = ['examples' => ['formats' => ['hostname' => 'api.example.net', 'email' => 'jane@example.com']]];

    $run = static function (array $representation, array $steps): string {
        $conversion = exampleConversion($steps, RepresentationPolicy::fromConfig($representation));

        return (string) json_encode([
            $conversion->schema,
            array_map(static fn (Diagnostic $d): array => [$d->code, $d->message], $conversion->diagnostics),
        ]);
    };

    $accepted = [kw('type', '"string"'), kw('format', '"email"')];
    $rejected = [kw('type', '"string"'), kw('format', '"email"'), kw('maxLength', '8')];

    expect($run($configured, $accepted))->toBe($run($configured, $accepted))
        ->and($run($reversed, $accepted))->toBe($run($configured, $accepted))
        ->and($run($configured, $rejected))->toBe($run($configured, $rejected))
        ->and($run($reversed, $rejected))->toBe($run($configured, $rejected))
        ->and($run($configured, $accepted))->toContain('jane@example.com');
});

/**
 * An author's own example is the contract as they stated it, and a suppressing rule ruled every value
 * out. Neither is a place a configured sample gets to speak.
 */
it('never lets a configured sample override an authored example or revive a suppressed one', function (): void {
    $policy = RepresentationPolicy::fromConfig(['examples' => ['formats' => ['email' => 'jane@example.com']]]);

    $authored = exampleConversion([kw('type', '"string"'), kw('format', '"email"'), kw('example', '"mine@example.org"')], $policy);
    $suppressed = exampleConversion([kw('type', '"string"'), kw('format', '"email"'), ['propose', null]], $policy);

    expect($authored->schema['properties']['f']['example'] ?? null)->toBe('mine@example.org')
        ->and($authored->diagnostics)->toBe([])
        ->and($suppressed->schema['properties']['f'] ?? [])->not->toHaveKey('example')
        ->and($suppressed->diagnostics)->toBe([]);
});

/**
 * The policy reader is the seam, so it takes the same shape of coercion as its neighbours: strings
 * survive, everything else is dropped rather than guessed at.
 */
it('reads examples.formats as strings keyed by format, dropping anything else', function (mixed $configured, array $expected): void {
    expect(RepresentationPolicy::fromConfig(['examples' => ['formats' => $configured]])->formatSamples)->toBe($expected);
})->with([
    'absent' => [null, []],
    'empty' => [[], []],
    'one format' => [['email' => 'jane@example.com'], ['email' => 'jane@example.com']],
    'several, in config order' => [
        ['hostname' => 'api.example.net', 'email' => 'jane@example.com'],
        ['hostname' => 'api.example.net', 'email' => 'jane@example.com'],
    ],
    'a non-string sample is dropped' => [['email' => ['nope'], 'hostname' => 'api.example.net'], ['hostname' => 'api.example.net']],
    'a scalar sample is not coerced' => [['email' => 42], []],
    'a non-array map' => ['jane@example.com', []],
]);

it('defaults the format samples to empty, so an absent config changes nothing', function (): void {
    expect(RepresentationPolicy::fromConfig([])->formatSamples)->toBe([])
        ->and((new RepresentationPolicy)->formatSamples)->toBe([])
        ->and(RepresentationPolicy::fromConfig(['examples' => 'nonsense'])->formatSamples)->toBe([]);
});
