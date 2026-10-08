<?php

declare(strict_types=1);

use Docuccino\Core\Draft\SchemaDraft;
use Docuccino\Core\Draft\SchemaKeywords;
use Docuccino\Core\Patch\Contribution;

/*
 * A declaration states its shape whole. Keywords compose as a conjunction, so a keyword the
 * declaration replaced but did not restate publishes something nobody said — which is what these
 * pin, one mutually exclusive group at a time. `frozenShape()` lives in tests/Pest.php.
 */

it('retracts the keywords a declared shape supersedes', function (array $inferred, array $declared, array $expected): void {
    expect(frozenShape($inferred, $declared))->toBe($expected);
})->with([
    // The reported case: a MapT body says every key maps to V, and the declared shape names the keys.
    // Left standing, `additionalProperties` publishes a permission the author's shape never granted.
    'a closed object shape over an inferred map' => [
        ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
        ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => ['a']],
        ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => ['a']],
    ],
    // And the other way about: a declared map is not the closed shape inference recovered.
    'an open map over an inferred closed shape' => [
        ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => ['a']],
        ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
        ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
    ],
    'a declared array over an inferred object' => [
        ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => ['a']],
        ['type' => 'array', 'items' => ['type' => 'string']],
        ['type' => 'array', 'items' => ['type' => 'string']],
    ],
    // A Schema Object's `$ref` siblings are applied, not ignored: `$ref` + `type: array` says the body
    // must satisfy both, which is a narrowing nobody declared — and usually nothing can satisfy.
    'a declared $ref over an inferred shape' => [
        ['type' => 'array', 'items' => ['type' => 'string']],
        ['$ref' => '#/components/schemas/Widget'],
        ['$ref' => '#/components/schemas/Widget'],
    ],
    'a declared scalar over an inferred container' => [
        ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1, 'uniqueItems' => true],
        ['type' => 'string'],
        ['type' => 'string'],
    ],
    'a declared union of types over an inferred object' => [
        ['type' => 'object', 'additionalProperties' => true],
        ['type' => ['string', 'null']],
        ['type' => ['string', 'null']],
    ],
    // Nothing else described the value, so there is nothing to retract and the declaration stands alone.
    'a declared shape over an empty draft' => [
        [],
        ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
        ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
    ],
]);

it('publishes a declared closed shape closed', function (): void {
    // The consumer's whole question: may I send a key the author did not name? A surviving
    // `additionalProperties` answers yes, in the schema of a shape that named its keys.
    $frozen = frozenShape(
        ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
        ['type' => 'object', 'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'integer']], 'required' => ['a', 'b']],
    );

    expect(array_keys($frozen))->toBe(['type', 'properties', 'required'])
        ->and($frozen)->not->toHaveKey('additionalProperties')
        ->and($frozen)->not->toHaveKey('patternProperties');
});

it('states a shape only where the write says what kind of value it is', function (array $declaration, array $expected): void {
    // A description or an example is not a shape, so it declares nothing about the body and supersedes
    // nothing — the independent-keyword case the guard was always right about.
    expect(SchemaKeywords::statesShape($declaration))->toBeFalse()
        ->and(frozenShape(['type' => 'object', 'additionalProperties' => ['type' => 'string']], $declaration))
        ->toBe($expected);
})->with([
    'a declared description' => [
        ['description' => 'The settings map.'],
        ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'description' => 'The settings map.'],
    ],
    'a declared example' => [
        ['example' => ['a' => 'b']],
        ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'example' => ['a' => 'b']],
    ],
    'a declared deprecation' => [
        ['deprecated' => true],
        ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'deprecated' => true],
    ],
]);

it('keeps what a higher layer stated beside a declared shape', function (): void {
    // Retraction is a guarded write, so it is bounded by precedence exactly as every other write is: an
    // overlay's keyword outranks the attribute declaring a type around it.
    $draft = new SchemaDraft;
    $draft->set('type', 'object', Contribution::inference());
    $draft->set('additionalProperties', ['type' => 'string'], Contribution::overlay());
    $draft->set('minProperties', 1, Contribution::overlay());

    $draft->declareShape(['type' => 'array', 'items' => ['type' => 'string']], Contribution::attribute());

    $frozen = $draft->freeze()->toArray();
    unset($frozen['x-docuccino']);

    // The declared type wins the field it outranks and the overlay's keywords stay put — the same answer
    // a keyword-by-keyword patch would give, which is the point: retraction adds no power of its own.
    expect($frozen)->toBe([
        'type' => 'array',
        'additionalProperties' => ['type' => 'string'],
        'minProperties' => 1,
        'items' => ['type' => 'string'],
    ]);
});

it('only shadows an equal layer, as a keyword write does', function (): void {
    // Two producers at one layer settle nothing: the incumbent keeps the field, so its keywords keep
    // their place too.
    expect(frozenShape(
        ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
        ['type' => 'string'],
        Contribution::attribute(),
        Contribution::attribute(),
    ))->toBe(['type' => 'object', 'additionalProperties' => ['type' => 'string']]);
});

it('keeps a refinement the declared type still admits', function (array $inferred, array $declared, array $expected): void {
    // A refinement is true of values of its type, not of the shape that carried it: an inferred
    // `format: date-time` still describes a declared string, and an enum still lists what the server
    // accepts. Only a declared type that excludes the refinement retires it.
    expect(frozenShape($inferred, $declared))->toBe($expected);
})->with([
    'a format under the same type' => [
        ['type' => 'string', 'format' => 'date-time'],
        ['type' => 'string'],
        ['type' => 'string', 'format' => 'date-time'],
    ],
    'a length under the same type' => [
        ['type' => 'string', 'minLength' => 3],
        ['type' => 'string'],
        ['type' => 'string', 'minLength' => 3],
    ],
    'an enum under any type' => [
        ['type' => 'string', 'enum' => ['a', 'b']],
        ['type' => 'string'],
        ['type' => 'string', 'enum' => ['a', 'b']],
    ],
    'a string refinement under a declared object' => [
        ['type' => 'string', 'minLength' => 3, 'pattern' => '^a'],
        ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
        ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
    ],
    'a numeric refinement under a declared string' => [
        ['type' => 'integer', 'minimum' => 1, 'multipleOf' => 2],
        ['type' => 'string'],
        ['type' => 'string'],
    ],
    'a numeric refinement under a declared number' => [
        ['type' => 'integer', 'minimum' => 1],
        ['type' => 'number'],
        ['type' => 'number', 'minimum' => 1],
    ],
    // `maxContains`/`minContains` bound how many items `contains` has to match, so they mean nothing
    // once the array is gone: retracting `contains` and leaving its bounds behind publishes a count of
    // matches against a keyword that is no longer there.
    'a contains bound under a declared string' => [
        ['type' => 'array', 'contains' => ['type' => 'string'], 'maxContains' => 2, 'minContains' => 1],
        ['type' => 'string'],
        ['type' => 'string'],
    ],
    'a contains bound under a declared array' => [
        ['type' => 'array', 'maxContains' => 2],
        ['type' => 'array'],
        ['type' => 'array', 'maxContains' => 2],
    ],
]);

it('keeps a schema-level annotation whatever shape is declared', function (): void {
    // The dialect a schema is written in and a note to its own readers say nothing about the instance,
    // so neither is retracted — and both are classified rather than merely unreadable, which is what
    // stops them being treated as a keyword nobody can place.
    expect(SchemaKeywords::classification()['$schema'])->toBe('annotation')
        ->and(SchemaKeywords::classification()['$comment'])->toBe('annotation')
        ->and(frozenShape(
            ['type' => 'array', '$schema' => 'https://json-schema.org/draft/2020-12/schema', '$comment' => 'internal'],
            ['type' => 'string'],
        ))->toBe(['type' => 'string', '$schema' => 'https://json-schema.org/draft/2020-12/schema', '$comment' => 'internal']);
});

it('takes the nested property drafts with the shape they belonged to', function (): void {
    // `properties` has two halves — the keyword and the nested drafts, which freeze() publishes over it.
    // A shape that supersedes the keyword has to take the drafts too, or the declared body loses to the
    // properties it replaced.
    $draft = new SchemaDraft;
    $draft->set('type', 'object', Contribution::inference());
    $draft->property('inferred')->set('type', 'string', Contribution::inference());
    $draft->property('pinned')->set('type', 'integer', Contribution::overlay());

    $draft->declareShape(['type' => 'object', 'properties' => ['a' => ['type' => 'string']]], Contribution::attribute());

    $frozen = $draft->freeze()->toArray();

    expect(array_keys($frozen['properties']))->toBe(['pinned'])
        ->and($frozen['properties']['pinned']['type'])->toBe('integer');
});

it('answers for every keyword it classifies', function (string $keyword, string $family): void {
    // The declaration states an object shape and restates nothing, so each family's answer is visible:
    // shape keywords go, an object refinement stays while a string one goes, annotations stay.
    $declaration = ['type' => 'object'];

    $superseded = match (true) {
        // The one keyword the declaration restates, and a restated keyword is overwritten on
        // precedence rather than retracted — which the second assertion pins for all of them.
        $keyword === 'type' => false,
        $family === 'shape' => true,
        $family === 'refinement' => ! in_array($keyword, ['minProperties', 'maxProperties', 'enum', 'const'], true),
        default => false,
    };

    expect(SchemaKeywords::isSuperseded($keyword, $declaration))->toBe($superseded)
        ->and(SchemaKeywords::isSuperseded($keyword, [...$declaration, $keyword => 'x']))->toBeFalse();
})->with(function () {
    foreach (SchemaKeywords::classification() as $keyword => $family) {
        yield $keyword => [$keyword, $family];
    }
});

it('names the instance types each keyword speaks about, and none for one that speaks about every type', function (string $keyword): void {
    // Stated from JSON Schema 2020-12's vocabularies rather than asked of the table: the array and object
    // applicators and validators apply to their one type, the string and numeric validators to theirs, and
    // everything else — `type`, `$ref`, the composition keywords, `enum`, `const` — whatever the type.
    $array = ['items', 'prefixItems', 'additionalItems', 'contains', 'unevaluatedItems', 'maxItems', 'minItems', 'uniqueItems', 'maxContains', 'minContains'];
    $object = ['properties', 'required', 'additionalProperties', 'patternProperties', 'propertyNames', 'unevaluatedProperties', 'dependentRequired', 'dependentSchemas', 'maxProperties', 'minProperties'];
    $string = ['maxLength', 'minLength', 'pattern', 'contentEncoding', 'contentMediaType', 'contentSchema'];
    $numeric = ['multipleOf', 'maximum', 'exclusiveMaximum', 'minimum', 'exclusiveMinimum'];

    expect(SchemaKeywords::typesOf($keyword))->toBe(match (true) {
        in_array($keyword, $array, true) => ['array'],
        in_array($keyword, $object, true) => ['object'],
        in_array($keyword, $string, true) => ['string'],
        in_array($keyword, $numeric, true) => ['integer', 'number'],
        // OpenAPI's formats name strings, integers and numbers alike.
        $keyword === 'format' => ['string', 'integer', 'number'],
        default => null,
    });
})->with(function () {
    foreach (array_keys(SchemaKeywords::classification()) as $keyword) {
        yield $keyword => [$keyword];
    }
});

it('leaves a keyword it cannot read exactly where it found it', function (string $keyword): void {
    // We do not retract what we cannot read: an unknown keyword survives a declared shape rather than
    // being dropped on a guess about what it meant.
    expect(SchemaKeywords::isSuperseded($keyword, ['type' => 'object']))->toBeFalse()
        ->and(frozenShape(['type' => 'array', $keyword => true], ['type' => 'object']))
        ->toBe(['type' => 'object', $keyword => true]);
})->with([
    'a vendor extension' => ['x-internal'],
    'a keyword from a later vocabulary' => ['propertyDependencies'],
]);

it('classifies every schema keyword the canonicalizer orders', function (): void {
    // The classification is the thing that goes stale — a hand list already did — so it is checked
    // against the canonicalizer's own schema keyword set rather than against a second copy of itself.
    $keywords = canonicalizerSchemaOrder();

    // A source scan that stopped matching would turn this into a test of nothing, so a floor and the
    // names it must find. Not the exact count: adding a keyword to the order is not what this guards.
    expect($keywords)->toHaveCount(count(array_unique($keywords)))
        ->and(count($keywords))->toBeGreaterThan(50)
        ->and($keywords)->toContain('$ref', 'type', 'properties', 'additionalProperties', 'items', 'description');

    expect(array_values(array_diff($keywords, array_keys(SchemaKeywords::classification()))))->toBe([]);
});

it('classifies every keyword it gives a subschema position', function (): void {
    // The two halves of the same table: a keyword whose value is a subschema describes the value's
    // shape by definition, so one knowing a keyword the other does not is the list going stale.
    foreach ([
        SchemaKeywords::POSITION_SCHEMA,
        SchemaKeywords::POSITION_SCHEMA_MAP,
        SchemaKeywords::POSITION_SCHEMA_LIST,
        SchemaKeywords::POSITION_STRING_LIST_MAP,
    ] as $position) {
        expect(SchemaKeywords::at($position))->not->toBeEmpty();
    }

    $positioned = [
        ...SchemaKeywords::objectValued(),
        ...SchemaKeywords::at(SchemaKeywords::POSITION_SCHEMA_LIST),
    ];

    expect(count($positioned))->toBeGreaterThan(20);

    // The three positioned keywords that describe something OTHER than the value carrying them, and so
    // are not shape claims about it: two subschema stores, and the decoded content of a string.
    $exceptions = ['$defs' => 'annotation', 'definitions' => 'annotation', 'contentSchema' => 'refinement'];

    foreach ($positioned as $keyword) {
        expect(SchemaKeywords::classification())->toHaveKey($keyword)
            ->and(SchemaKeywords::classification()[$keyword])->toBe($exceptions[$keyword] ?? 'shape');
    }
});

/*
 * The guard on the one shape of this defect a guard can catch: a stale SECOND copy of the set. Nine have
 * shipped — three in the example audit, two in the 3.0 downlevel, three in the canonicalizer, one in the
 * structural hash. So a declaration anywhere in the packages naming three or more of these keywords is
 * either one of the copies below — each stated for a reason that is not "which keywords carry
 * subschemas" — or a copy nobody has retired yet. The wider class is not copies at all and is described
 * in docs/design/uir-and-extensions.md §1 "The empty-object invariant".
 *
 * What it reads is a DECLARATION, tokenised ({@see literalSetDeclarations()}), rather than the one
 * spelling `const array NAME = [` with single-quoted members: a set can be listed by an untyped const, a
 * static property or a `match`, and a member can be written in either quote style. Every existing reader
 * of the table is match-shaped, so that was the likeliest spelling of the next copy and the one the scan
 * could not see.
 */
it('keeps the subschema keyword set in one place, so no reader can carry a stale copy', function (): void {
    $sanctioned = [
        // The table itself, and the classification that is the other half of it.
        'core/src/Draft/SchemaKeywords.php::SUBSCHEMA_POSITIONS',
        'core/src/Draft/SchemaKeywords.php::SHAPE',
        // The member ORDER, which is a normative choice rather than a fact about the keyword. Held
        // against the table by the two guards above.
        'core/src/Canonical/Canonicalizer.php::SCHEMA_ORDER',
        // What OpenAPI 3.0 does not define, which is a fact about 3.0 and not about a position. Held
        // against the vendored 3.0 meta-schema by OpenApi30DownlevelTest.
        'core/src/Emit/OpenApi30DownlevelEmitter.php::UNSUPPORTED_SCHEMA_KEYWORDS',
        // Which applicators the example rewrite can walk a value in step with — a fact about that
        // walk, not about the position. The complement is DERIVED from the table rather than listed
        // beside it, so a keyword added there is undecidable until somebody teaches the walk to read
        // it; ChangedFieldExamplesTest holds the derivation against the table in both directions.
        'core/src/Document/ChangedFieldExamples.php::RESOLVED',
        // Which members hold a map of NAMES rather than keywords — a fact about JSON structure, not
        // about which positions carry subschemas. The two overlap because most name maps hold schemas,
        // and they disagree where it counts: `dependentSchemas` carries subschemas and is absent here,
        // `definitions` is present. It is what tells a walk over a schema that `properties.$ref` is a
        // property; the OpenAPI half of the same fact composes onto it rather than restating it.
        'core/src/SpecValidation/OpenApiMetaSchema.php::SCHEMA_NAME_MAPS',
    ];

    $positioned = [
        ...SchemaKeywords::objectValued(),
        ...SchemaKeywords::at(SchemaKeywords::POSITION_SCHEMA_LIST),
    ];

    $root = dirname(__DIR__, 4).'/php/';
    $found = [];

    foreach (['core', 'attributes', 'laravel', 'inference-phpstan'] as $package) {
        $directory = new RecursiveDirectoryIterator($root.$package.'/src');

        foreach (new RecursiveIteratorIterator($directory) as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            $relative = $package.'/src/'.str_replace($root.$package.'/src/', '', $file->getPathname());

            foreach (array_keys(literalSetDeclarations($source, $positioned)) as $declaration) {
                $found[] = $relative.'::'.$declaration;
            }
        }
    }

    sort($found);
    sort($sanctioned);

    // Both directions: a new copy fails, and so does a scan that stopped seeing the sanctioned ones.
    expect($found)->toBe($sanctioned);
});

it('sees a keyword set listed any way PHP can list one, and nothing that merely uses one', function (): void {
    // The scanner's own proof. Every spelling a copy could take — and the three shapes that name these
    // keywords without listing them, each of which the guard flagged as a copy before it read grammar.
    $source = <<<'PHP'
        <?php

        final class Copies
        {
            /** A docblock naming items, properties and allOf is prose. */
            public const array TYPED = ['items', 'properties', 'allOf'];

            public const UNTYPED_2 = ["items", "properties", "allOf"];

            private static array $property = ['items', 'properties', 'allOf'];

            public const array TABLE = ['items' => self::A, 'properties' => self::B, 'allOf' => self::C];

            public const array SHORT = ['items', 'properties'];

            // 'items', 'properties', 'allOf' in a comment is not a list.
            public function matched(string $keyword): int
            {
                return match ($keyword) {
                    'items', 'properties', 'allOf' => 1,
                    default => 0,
                };
            }

            public function reads(array $schema): bool
            {
                return isset($schema['items']) || isset($schema['properties']) || isset($schema['allOf']);
            }

            public function builds(): array
            {
                return [
                    'items' => ['type' => 'string'],
                    'properties' => ['a' => ['type' => 'string']],
                    'allOf' => [['type' => 'string']],
                ];
            }
        }
        PHP;

    $found = literalSetDeclarations($source, ['items', 'properties', 'allOf']);

    // A typed const, an untyped one whose name carries a digit, a static property, a keyword-to-scalar
    // table and a `match` are all five a list. `SHORT` names two, so it is under the threshold; `reads`
    // dereferences one keyword three times; `builds` is a schema, and a schema is data.
    expect($found)->toBe([
        'TYPED' => 3,
        'UNTYPED_2' => 3,
        'property' => 3,
        'TABLE' => 3,
        'matched' => 3,
    ]);
});

it('gives draft-07 dependencies no position, because one position cannot describe it', function (): void {
    // Its members are EITHER a subschema or a list of property names, decided by the member's own
    // value — so no single position is right, and it is left as data rather than rewritten on a guess.
    expect(SchemaKeywords::positionOf('dependencies'))->toBeNull()
        ->and(SchemaKeywords::positionOf('dependentSchemas'))->toBe(SchemaKeywords::POSITION_SCHEMA_MAP)
        ->and(SchemaKeywords::positionOf('dependentRequired'))->toBe(SchemaKeywords::POSITION_STRING_LIST_MAP)
        // And so it is never retracted either: we do not retract what we cannot read.
        ->and(SchemaKeywords::isSuperseded('dependencies', ['type' => 'object']))->toBeFalse();
});

it('supersedes a contentSchema only where the declared type is no longer a string', function (): void {
    // It carries a subschema and is still type-bound, exactly like the two `content*` keywords beside
    // it: a declared `string` keeps it, anything else leaves it describing nothing.
    expect(SchemaKeywords::isSuperseded('contentSchema', ['type' => 'string']))->toBeFalse()
        ->and(SchemaKeywords::isSuperseded('contentSchema', ['type' => 'object']))->toBeTrue()
        ->and(SchemaKeywords::isSuperseded('definitions', ['type' => 'object']))->toBeFalse();
});

it('answers no for a keyword its annotation-only set does not name', function (): void {
    // Unknown-entry degradation: a keyword nothing classifies is a keyword the diff must keep reporting,
    // so the safe answer is "not an annotation" rather than "not a change".
    expect(SchemaKeywords::isAnnotationOnly('type'))->toBeFalse()
        ->and(SchemaKeywords::isAnnotationOnly('x-vendor-note'))->toBeFalse()
        ->and(SchemaKeywords::isAnnotationOnly('summary'))->toBeFalse()
        ->and(SchemaKeywords::isAnnotationOnly(''))->toBeFalse()
        ->and(SchemaKeywords::annotationOnly())->not->toBeEmpty();
});

/*
 * What a `contains` ASSERTS, which is a fact about the schema carrying it rather than about the keyword
 * — the floor defaults to 1, `minContains: 0` drops the demand for a matching element, and a
 * `maxContains` beside it still bounds how many may match. Both readers ask here: the example factory,
 * deciding what array it may publish, and the diff, deciding whether an arriving `contains` narrows
 * anything. A second reading of that default anywhere is a second answer to one question.
 */
it('reads what a contains asserts, bounds and all', function (array $schema, bool $asserts, int $atLeast, ?int $atMost): void {
    expect(SchemaKeywords::containsAsserts($schema))->toBe($asserts)
        ->and(SchemaKeywords::minContains($schema))->toBe($atLeast)
        ->and(SchemaKeywords::maxContains($schema))->toBe($atMost);
})->with([
    // Bounds with no `contains` beside them bound nothing at all.
    'no contains at all' => [['type' => 'array'], false, 1, null],
    'bounds with no contains to bound' => [['type' => 'array', 'minContains' => 0, 'maxContains' => 3], false, 0, 3],
    // Present, the keyword asserts a match unless the floor is explicitly dropped.
    'contains with no bounds' => [['contains' => ['type' => 'string']], true, 1, null],
    'contains at the floor it defaults to' => [['contains' => ['type' => 'string'], 'minContains' => 1], true, 1, null],
    'contains with the demand dropped' => [['contains' => ['type' => 'string'], 'minContains' => 0], false, 0, null],
    // The half a floor-only reading gets wrong: nothing has to match, and what may match is still capped.
    'contains with the demand dropped and a cap left' => [
        ['contains' => ['type' => 'string'], 'minContains' => 0, 'maxContains' => 2],
        true,
        0,
        2,
    ],
    'contains capped at none' => [['contains' => ['type' => 'string'], 'maxContains' => 0], true, 1, 0],
    'contains wanted more than once' => [['contains' => ['type' => 'string'], 'minContains' => 3], true, 3, null],
    // Degradation: a bound that is no integer is no bound, so the floor is the keyword's own default and
    // there is no cap — a cap read out of a string would bound a document nobody wrote.
    'contains with bounds that are no numbers' => [
        ['contains' => ['type' => 'string'], 'minContains' => '2', 'maxContains' => '2'],
        true,
        1,
        null,
    ],
    // Presence is what the assertion turns on, and `false` is a subschema like any other.
    'contains a boolean subschema' => [['contains' => false], true, 1, null],
]);

it('names every keyword that says nothing about the instance, and nothing that does', function (): void {
    // The set backing this answers SUPERSESSION — which standing keywords a declared shape retracts —
    // and a reader deciding whether one value satisfies a schema asks a different question of it. A
    // constraining keyword joining that set would be read here as "nothing to check" and publish an
    // example the schema forbids, so the two are held apart by classification rather than by hand.
    $constraining = array_keys(array_filter(
        SchemaKeywords::classification(),
        static fn (string $family): bool => $family !== 'annotation',
    ));

    expect(array_values(array_intersect(SchemaKeywords::annotations(), $constraining)))->toBe([])
        // Anti-vacuity: an empty set on either side would pass the intersection above forever.
        ->and(count(SchemaKeywords::annotations()))->toBeGreaterThan(10)
        ->and(count($constraining))->toBeGreaterThan(20);

    foreach (SchemaKeywords::annotations() as $keyword) {
        expect(SchemaKeywords::saysNothingAboutTheInstance($keyword))->toBeTrue();
    }

    // The guard EXECUTED: every one of these constrains an instance, and none may ever answer true.
    foreach (['type', 'const', 'enum', 'pattern', 'maxLength', 'items', 'contains', 'not', 'required', 'x-vendor'] as $keyword) {
        expect(SchemaKeywords::saysNothingAboutTheInstance($keyword))->toBeFalse();
    }
});

it('reads a composition as a declared shape, and an array\'s list of items as none', function (array $declaration, bool $states): void {
    // A union is spelled as a composition — a nullable class among them — so it says what kind of value
    // this is as surely as a `type` does. `prefixItems` is also a list of subschemas, but an array's.
    expect(SchemaKeywords::statesShape($declaration))->toBe($states);
})->with([
    'anyOf' => [['anyOf' => [['$ref' => '#/components/schemas/A'], ['type' => 'null']]], true],
    'oneOf' => [['oneOf' => [['$ref' => '#/components/schemas/A'], ['$ref' => '#/components/schemas/B']]], true],
    'allOf' => [['allOf' => [['$ref' => '#/components/schemas/A']]], true],
    'prefixItems' => [['prefixItems' => [['type' => 'string']]], false],
]);

it('keeps of a standing value list only what the declared type can hold', function (array $standing, array $declared, array $expected): void {
    // `type: integer` beside `enum: ["1", "2"]` is a schema no value satisfies — a degraded answer has to
    // stay true, so a list with nothing the type holds goes with the type that held it. The values it does
    // hold stay, since they list what the server accepts and the declaration admits; and a declaration
    // naming no type of its own cannot be read for this, so it leaves the list where it stood.
    expect(frozenShape($standing, $declared))->toBe($expected)
        ->and(SchemaKeywords::declaredOver($declared, $standing))->toEqual($expected);
})->with([
    'string values under a declared integer' => [['type' => 'string', 'enum' => ['1', '2']], ['type' => 'integer'], ['type' => 'integer']],
    'a string const under a declared integer' => [['type' => 'string', 'const' => 'a'], ['type' => 'integer'], ['type' => 'integer']],
    'integers under a declared number' => [['type' => 'integer', 'enum' => [1, 2]], ['type' => 'number'], ['type' => 'number', 'enum' => [1, 2]]],
    'a null among values under a declared nullable string' => [['type' => ['string', 'null'], 'enum' => ['a', null]], ['type' => ['string', 'null']], ['type' => ['string', 'null'], 'enum' => ['a', null]]],
    'a null among values under a declared string' => [['type' => ['string', 'null'], 'enum' => ['a', null]], ['type' => 'string'], ['type' => 'string', 'enum' => ['a']]],
    'values under a declared reference' => [['type' => 'string', 'enum' => ['a']], ['$ref' => '#/components/schemas/Kind'], ['enum' => ['a'], '$ref' => '#/components/schemas/Kind']],
]);

it('gives the same answer written as arrays as it does on a draft', function (array $standing, array $declared): void {
    // Two sites compute one rule: a draft's declared shape and a request body's declared field. Held to
    // each other over every family the rule answers for — keys in whatever order each writes them.
    $arrays = SchemaKeywords::declaredOver($declared, $standing);
    $draft = frozenShape($standing, $declared);
    ksort($arrays);
    ksort($draft);

    expect($arrays)->toBe($draft);
})->with([
    'a closed object over a map' => [['type' => 'object', 'additionalProperties' => ['type' => 'string']], ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]]],
    'a reference over a shape' => [['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Kept.'], ['$ref' => '#/components/schemas/Widget']],
    'a union over a shape' => [['type' => 'object', 'maxProperties' => 3, 'title' => 'Kept.'], ['anyOf' => [['$ref' => '#/components/schemas/Widget'], ['type' => 'null']]]],
    'a refinement the type admits' => [['type' => 'string', 'format' => 'email', 'maxLength' => 9], ['type' => 'string']],
    'a refinement the type does not' => [['type' => 'integer', 'minimum' => 1], ['type' => 'string']],
    'a description alone' => [['type' => 'object', 'additionalProperties' => true], ['description' => 'The settings map.']],
    'an annotation restated' => [['type' => 'string', 'description' => 'Old.'], ['type' => 'string', 'description' => 'New.']],
    'an unknown keyword' => [['type' => 'array', 'x-custom' => true], ['type' => 'object']],
]);

it('keeps an example only where the declared type can hold it, and an enum\'s names only beside its values', function (array $standing, array $declared, array $expected): void {
    // An example of a value the type refuses is no example of it, and a name for an enum value names
    // nothing once the value is gone — each decoration is positional over the `enum`, so it narrows with it.
    // Held at both sites the rule is computed at.
    $arrays = SchemaKeywords::declaredOver($declared, $standing);
    $draft = frozenShape($standing, $declared);
    ksort($arrays);
    ksort($draft);
    ksort($expected);

    expect($arrays)->toEqual($expected)
        ->and($draft)->toEqual($expected);
})->with([
    'a string example under a declared integer' => [['type' => 'string', 'example' => 'low', 'default' => 'low'], ['type' => 'integer'], ['type' => 'integer']],
    'an integer example under a declared number' => [['type' => 'integer', 'example' => 3, 'default' => 1], ['type' => 'number'], ['type' => 'number', 'example' => 3, 'default' => 1]],
    'examples, some held' => [['type' => ['string', 'integer'], 'examples' => ['a', 1]], ['type' => 'integer'], ['type' => 'integer', 'examples' => [1]]],
    'an example the declaration restates' => [['type' => 'string', 'example' => 'low'], ['type' => 'integer', 'example' => 2], ['type' => 'integer', 'example' => 2]],
    'names for values the type cannot hold' => [
        ['type' => 'string', 'enum' => ['low', 'high'], 'x-enum-varnames' => ['Low', 'High'], 'x-enumNames' => ['Low', 'High'], 'x-enum-descriptions' => ['L', 'H'], 'x-enumDescriptions' => ['low' => 'L', 'high' => 'H']],
        ['type' => 'integer'],
        ['type' => 'integer'],
    ],
    'names for the values kept' => [
        ['type' => ['string', 'null'], 'enum' => ['low', null, 'high'], 'x-enum-varnames' => ['Low', 'Null', 'High'], 'x-enumDescriptions' => ['low' => 'L', 'high' => 'H']],
        ['type' => 'string'],
        ['type' => 'string', 'enum' => ['low', 'high'], 'x-enum-varnames' => ['Low', 'High'], 'x-enumDescriptions' => ['low' => 'L', 'high' => 'H']],
    ],
    'descriptions keyed by numeric values' => [
        ['type' => ['integer', 'string'], 'enum' => [1, 2, 'x'], 'x-enumDescriptions' => (object) ['1' => 'One', '2' => 'Two', 'x' => 'Ex']],
        ['type' => 'integer'],
        ['type' => 'integer', 'enum' => [1, 2], 'x-enumDescriptions' => [1 => 'One', 2 => 'Two']],
    ],
    'names beside every value kept' => [
        ['type' => 'string', 'enum' => ['low', 'high'], 'x-enum-varnames' => ['Low', 'High']],
        ['type' => 'string'],
        ['type' => 'string', 'enum' => ['low', 'high'], 'x-enum-varnames' => ['Low', 'High']],
    ],
]);
