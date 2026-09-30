<?php

declare(strict_types=1);

use Docuccino\Core\Contract\ContractIndex;
use Docuccino\Core\Contract\Pointer;
use Docuccino\Core\Contract\SchemaCheck;
use Docuccino\Core\Contract\Violation;
use Opis\JsonSchema\Uri;

/*
 * A check hands the validator its subject and the component schemas the subject can reach, not every
 * component the document declares: the validator walks everything it is handed before validating, so the
 * whole of `components/schemas` made each check cost the document. These hold both halves — what the
 * validator is handed and walks, and that a violation deep inside a reached component is still found
 * for every spelling of the reference that reaches it and every name the path to it passes through.
 */

it('reaches each component through a document of its own that every check shares, walked once', function (): void {
    $recorder = recordingSchemaValidators();
    $check = new SchemaCheck(reachableDefsDocument(['$ref' => '#/components/schemas/Middle']), $recorder->factory());

    foreach (range(1, 3) as $_) {
        $check->check((object) ['leaf' => (object) ['n' => 1]], reachableDefsSubject(), 'the body');
    }

    // The root carries none of them: its reference lands in Middle's own document, and one validator —
    // one loader, one walk and one parse of each component — answered all three checks. `Unreached` is
    // never walked at all, so the first check costs what it reaches rather than the whole document.
    expect(property_exists($recorder->roots[0], '$defs'))->toBeFalse()
        ->and($recorder->roots[0]->{'$ref'})->toBe('schema:///components/0.json#/$defs/Middle')
        ->and($recorder->roots)->toHaveCount(3)
        ->and($recorder->created)->toBe(1)
        ->and($recorder->resolved)->toBe(['schema:///components/0.json#', 'schema:///components/1.json#']);
});

it('reads a component the first time a check reaches it, and none it never reaches', function (): void {
    // A contract suite builds a checker per assertion, so one that read every component up front cost
    // each assertion the whole document. Every component is read only where the subject names a schema
    // some way a pointer cannot say, since then every one of them travels.
    $read = static fn (SchemaCheck $check): array => array_map(strval(...), array_keys((array) (new ReflectionProperty($check, 'defs'))->getValue($check)));

    $reaching = new SchemaCheck(reachableDefsDocument(['$ref' => '#/components/schemas/Middle']));
    $reaching->check((object) [], reachableDefsSubject(), 'the body');

    $anchored = new SchemaCheck(reachableDefsDocument(['$ref' => '#leaf'], ['Anchored' => ['$anchor' => 'leaf']]));
    $anchored->check((object) [], reachableDefsSubject(), 'the body');

    expect($read($reaching))->toEqualCanonicalizing(['Middle', 'Leaf'])
        ->and($read($anchored))->toBe(['Middle', 'Leaf', 'Unreached', 'Anchored']);
});

it('answers for its own documents alone, and for no component a shared check cannot reach', function (): void {
    // The shared validator hands it every `schema:` id it cannot find, so anything else is refused
    // outright rather than answered with a component, and so is a component that names a schema some
    // other way, which no shared check ever reaches.
    $recorder = recordingSchemaValidators();
    $check = new SchemaCheck(reachableDefsDocument(['$ref' => '#/components/schemas/Leaf'], ['Anchored' => ['$anchor' => 'a']]), $recorder->factory());

    $check->check((object) ['n' => 1], reachableDefsSubject(), 'the body');
    $resolver = $recorder->validators[0]->resolver();

    expect($resolver?->resolve(Uri::parse('schema:///components/1.json#', true)))->toBeObject()
        ->and($resolver?->resolve(Uri::parse('schema:///components/3.json#', true)))->toBeNull()
        ->and($resolver?->resolve(Uri::parse('schema:///components/9.json#', true)))->toBeNull()
        ->and($resolver?->resolve(Uri::parse('schema:///components.json#', true)))->toBeNull()
        ->and($resolver?->resolve(Uri::parse('schema:///elsewhere/1.json#', true)))->toBeNull();
});

/*
 * What a component that names a schema some other way declares is registered wherever the validator walks
 * it, instance data included — two anchors alike, or an id standing where a document of ours would. A
 * subject that cannot reach such a component is answered on what it does reach, the same every time and
 * in any order: nothing it would refuse is ever walked, so nothing can abort a walk halfway and leave
 * the next check an answer that depends on which ran first.
 */
dataset('components claiming one name between them', [
    'declared after the ones checked' => [false],
    'declared before them' => [true],
]);

it('answers every check alike whatever the components it cannot reach claim, and in any order', function (bool $claimsFirst): void {
    $claims = [
        'DupA' => ['$anchor' => 'dup', 'type' => 'string'],
        'DupB' => ['$anchor' => 'dup', 'type' => 'integer'],
        'Squatter' => ['$id' => 'schema:///components.json', 'type' => 'string'],
        'Tenant' => ['type' => 'object', 'example' => ['$id' => 'schema:///components/1.json']],
    ];
    $chain = [
        'Middle' => ['type' => 'object', 'properties' => ['leaf' => ['$ref' => '#/components/schemas/Leaf']]],
        'Leaf' => ['type' => 'object', 'properties' => ['n' => ['type' => 'integer']]],
    ];
    $response = static fn (string $name): array => ['description' => 'ok', 'content' => ['application/json' => [
        'schema' => ['$ref' => '#/components/schemas/'.$name],
    ]]];

    $check = new SchemaCheck(ContractIndex::fromArray([
        'openapi' => '3.2.0',
        'info' => ['title' => 't', 'version' => '1'],
        'paths' => ['/things' => ['get' => ['responses' => ['200' => $response('Leaf'), '201' => $response('Middle')]]]],
        'components' => ['schemas' => $claimsFirst ? [...$claims, ...$chain] : [...$chain, ...$claims]],
    ]));

    $answers = [];
    foreach ([['200', (object) ['n' => 'x']], ['201', (object) ['leaf' => (object) ['n' => 'x']]], ['200', (object) ['n' => 'x']]] as [$status, $body]) {
        $violations = $check->check($body, ['paths', '/things', 'get', 'responses', $status, 'content', 'application/json', 'schema'], 'the body');
        $answers[] = array_map(static fn (Violation $v): string => $v->pointer, $violations);
    }

    expect($answers)->toBe([['/n'], ['/leaf/n'], ['/n']]);
})->with('components claiming one name between them');

it('refuses a subject that reaches a component claiming a name another claims, alike every time', function (): void {
    // The one case the validator must walk them: the subject reaches the claim, so every component
    // travels with a root of its own, and the claim is refused in the validator's words — as it always was.
    $check = new SchemaCheck(reachableDefsDocument(['$ref' => '#/components/schemas/DupA'], [
        'DupA' => ['$anchor' => 'dup', 'type' => 'string'],
        'DupB' => ['$anchor' => 'dup', 'type' => 'integer'],
    ]));

    foreach (range(1, 2) as $_) {
        expect(fn () => $check->check('x', reachableDefsSubject(), 'the body'))
            ->toThrow(RuntimeException::class, 'Duplicate schema id');
    }
});

it('keeps a root of its own for a subject whose own $defs shadow the components', function (): void {
    $recorder = recordingSchemaValidators();
    $check = new SchemaCheck(reachableDefsDocument([
        '$defs' => ['Local' => ['type' => 'integer']],
        'properties' => ['a' => ['$ref' => '#/$defs/Local'], 'b' => ['$ref' => '#/components/schemas/Leaf']],
    ]), $recorder->factory());

    $violations = $check->check((object) ['a' => 'x'], reachableDefsSubject(), 'the body');

    expect(array_keys(get_object_vars($recorder->roots[0]->{'$defs'})))->toBe(['Leaf', 'Local'])
        ->and($violations)->toHaveCount(1)
        ->and($violations[0]->pointer)->toBe('/a');
});

it('lets a subject\'s own $defs shadow a component of the same name, as they always did', function (): void {
    // The subject's `Leaf` is an integer and the component's is an object: which one a reference lands
    // on is the whole answer, and a root of its own is where the subject's wins.
    $check = new SchemaCheck(reachableDefsDocument([
        '$defs' => ['Leaf' => ['type' => 'integer']],
        'properties' => ['b' => ['$ref' => '#/components/schemas/Leaf']],
    ]));

    $violations = $check->check((object) ['b' => 'x'], reachableDefsSubject(), 'the body');

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->message)->toBe('The data (string) must match the type: integer');
});

/*
 * A `const` or an `enum` holding a `$ref` member states a value the instance must equal, whatever that
 * member is called. Rewritten the way a real reference is, the value would stop matching the very
 * instance the document states and start matching one it does not — on a root of its own as much as on
 * the shared components, since both are rewritten by one walk.
 */
dataset('a $ref a value states', [
    'a const' => [['const' => ['$ref' => '#/components/schemas/Leaf']]],
    'an enum' => [['enum' => [['$ref' => '#/components/schemas/Leaf']]]],
]);

dataset('the root a check is handed', [
    'on the shared components' => [[]],
    'on a root of its own' => [['$defs' => ['Local' => ['type' => 'string']]]],
]);

it('compares a $ref a const or an enum states as the value the document states', function (array $subject, array $root): void {
    $check = new SchemaCheck(reachableDefsDocument([...$subject, ...$root]));

    expect($check->check((object) ['$ref' => '#/components/schemas/Leaf'], reachableDefsSubject(), 'the body'))->toBe([])
        ->and($check->check((object) ['$ref' => '#/$defs/Leaf'], reachableDefsSubject(), 'the body'))->toHaveCount(1);
})->with('a $ref a value states')->with('the root a check is handed');

it('hands the validator every value a schema states exactly as the document states it', function (string $member, array $root): void {
    // A reference, an empty list and a member spelled like a keyword: each is what the value says, so
    // nothing in it is rewritten, repaired or pointed anywhere.
    $value = ['$ref' => '#/components/schemas/Leaf', 'properties' => [], 'default' => ['$ref' => '#/$defs/Leaf']];
    $index = reachableDefsDocument(['type' => 'object', $member => in_array($member, ['enum', 'examples'], true) ? [$value] : $value, ...$root]);
    $recorder = recordingSchemaValidators();

    (new SchemaCheck($index, $recorder->factory()))->check((object) ['$ref' => 'x'], reachableDefsSubject(), 'the body');

    expect(json_encode($recorder->roots[0]->{$member}))
        ->toBe(json_encode(Pointer::readGraph($index->graph(), [...reachableDefsSubject(), $member])));
})->with(['const', 'default', 'enum', 'example', 'examples', 'x-extension'])->with('the root a check is handed');

/*
 * A key in a map of names is whatever the author called the thing, so a property called `default` holds
 * a schema exactly as one called `name` does, and so does a `$defs` entry, a pattern or a dependency
 * spelled like a keyword. Each row reaches `Leaf` through such a name and expects the violation inside it.
 */
dataset('names spelled like keywords', [
    'an ordinary name' => ['name'],
    'const' => ['const'],
    'default' => ['default'],
    'enum' => ['enum'],
    'example' => ['example'],
    'examples' => ['examples'],
    'an extension' => ['x-name'],
    'a keyword holding a schema' => ['items'],
    'a keyword holding a map' => ['properties'],
    '$ref' => ['$ref'],
    '$id' => ['$id'],
]);

dataset('maps of names', [
    'properties' => [static fn (string $name): array => [
        ['type' => 'object', 'properties' => [$name => ['$ref' => '#/components/schemas/Leaf']]],
        (object) [$name => (object) ['n' => 'x']],
        Pointer::of([$name, 'n']),
    ]],
    // A pattern matches its own spelling; a `$` is escaped, since unescaped it anchors nothing can follow.
    'patternProperties' => [static fn (string $name): array => [
        ['type' => 'object', 'patternProperties' => [str_replace('$', '\$', $name) => ['$ref' => '#/components/schemas/Leaf']]],
        (object) [$name => (object) ['n' => 'x']],
        Pointer::of([$name, 'n']),
    ]],
    'dependentSchemas' => [static fn (string $name): array => [
        ['dependentSchemas' => [$name => ['$ref' => '#/components/schemas/Leaf']]],
        (object) [$name => 1, 'n' => 'x'],
        '/n',
    ]],
    // Draft-07's spelling, which the validator still reads.
    'dependencies' => [static fn (string $name): array => [
        ['dependencies' => [$name => ['$ref' => '#/components/schemas/Leaf']]],
        (object) [$name => 1, 'n' => 'x'],
        '/n',
    ]],
    '$defs' => [static fn (string $name): array => [
        ['$ref' => '#/$defs/'.$name, '$defs' => [$name => ['$ref' => '#/components/schemas/Leaf']]],
        (object) ['n' => 'x'],
        '/n',
    ]],
    'definitions' => [static fn (string $name): array => [
        ['$ref' => '#/definitions/'.$name, 'definitions' => [$name => ['$ref' => '#/components/schemas/Leaf']]],
        (object) ['n' => 'x'],
        '/n',
    ]],
]);

it('reads a name spelled like a keyword as the name it is, at every map of names', function (Closure $map, string $name): void {
    [$subject, $body, $pointer] = $map($name);

    $violations = (new SchemaCheck(reachableDefsDocument($subject)))->check($body, reachableDefsSubject(), 'the body');

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->pointer)->toBe($pointer)
        ->and($violations[0]->schemaPointer)->toBe('/components/schemas/Leaf/properties/n');
})->with('maps of names')->with('names spelled like keywords');

/*
 * An empty array where a schema or a map belongs is the empty one, decided by where it stands — a map
 * entry, a list item, a keyword holding an object — never by the name of the key holding it. The rows
 * that are not keyword values are schemas that used to be refused unless their name happened to be one.
 */
dataset('schemas written as an empty array', [
    'a property' => [['type' => 'object', 'properties' => ['field' => []]], [], (object) ['field' => 1]],
    'a property named like a keyword holding a schema' => [['type' => 'object', 'properties' => ['items' => []]], [], (object) ['items' => 1]],
    'a keyword inside a property named like instance data' => [
        ['type' => 'object', 'properties' => ['default' => ['type' => 'object', 'properties' => []]]], [], (object) ['default' => (object) []],
    ],
    'a pattern' => [['type' => 'object', 'patternProperties' => ['^f' => []]], [], (object) ['field' => 1]],
    'a dependent schema' => [['dependentSchemas' => ['field' => []]], [], (object) ['field' => 1]],
    'a branch of an allOf' => [['allOf' => [[]]], [], 1],
    'a $defs entry' => [['$ref' => '#/$defs/Empty', '$defs' => ['Empty' => []]], [], 1],
    'a component' => [['$ref' => '#/components/schemas/Empty'], ['Empty' => []], 1],
]);

it('reads an empty array standing for a schema as the empty schema, wherever it stands', function (array $subject, array $extra, mixed $body): void {
    expect((new SchemaCheck(reachableDefsDocument($subject, $extra)))->check($body, reachableDefsSubject(), 'the body'))->toBe([]);
})->with('schemas written as an empty array');

it('keeps a root of its own for a subject reaching a component that points back into the root', function (): void {
    // In a root of its own, `#/properties/n` inside a component names the subject's own member; in the
    // shared copy it would name something else entirely, so that component never reads it from there.
    $recorder = recordingSchemaValidators();
    $check = new SchemaCheck(reachableDefsDocument(
        ['properties' => ['n' => ['type' => 'integer'], 'echo' => ['$ref' => '#/components/schemas/Echo']]],
        ['Echo' => ['$ref' => '#/properties/n']],
    ), $recorder->factory());

    $violations = $check->check((object) ['echo' => 'x'], reachableDefsSubject(), 'the body');

    expect(array_keys(get_object_vars($recorder->roots[0]->{'$defs'})))->toBe(['Echo'])
        ->and($violations)->toHaveCount(1)
        ->and($violations[0]->pointer)->toBe('/echo');
});

it('keeps a root of its own for a subject whose reference names a component the document lacks', function (): void {
    $recorder = recordingSchemaValidators();
    $check = new SchemaCheck(reachableDefsDocument(['$ref' => '#/components/schemas/Missing']), $recorder->factory());

    expect(fn () => $check->check((object) [], reachableDefsSubject(), 'the body'))->toThrow(RuntimeException::class)
        ->and($recorder->roots[0]->{'$ref'})->toBe('#/$defs/Missing');
});

it('hands a subject that references nothing no components at all', function (): void {
    $recorder = recordingSchemaValidators();
    $check = new SchemaCheck(reachableDefsDocument(['type' => 'object']), $recorder->factory());

    $check->check((object) [], reachableDefsSubject(), 'the body');

    expect(property_exists($recorder->roots[0], '$defs'))->toBeFalse();
});

it('hands every component over where the subject names one some way a pointer cannot say', function (): void {
    $recorder = recordingSchemaValidators();
    $check = new SchemaCheck(
        reachableDefsDocument(['$ref' => '#leaf'], ['Anchored' => ['$anchor' => 'leaf', 'type' => 'integer']]),
        $recorder->factory(),
    );

    $violations = $check->check('not an integer', reachableDefsSubject(), 'the body');

    expect(array_keys(get_object_vars($recorder->roots[0]->{'$defs'})))->toBe(['Middle', 'Leaf', 'Unreached', 'Anchored'])
        ->and($violations)->toHaveCount(1)
        ->and($violations[0]->schemaPointer)->toBe('/components/schemas/Anchored');
});

dataset('spellings of a reference to Middle', [
    'the component pointer' => ['#/components/schemas/Middle', 'Middle'],
    'a $defs pointer written by hand' => ['#/$defs/Middle', 'Middle'],
    'a percent-encoded $defs' => ['#/%24defs/Middle', 'Middle'],
    'an escaped slash in the name' => ['#/components/schemas/Mid~1dle', 'Mid/dle'],
    'a percent-encoded name' => ['#/components/schemas/Mid%20dle', 'Mid dle'],
]);

it('finds a violation two components deep, however the reference to them is spelled', function (string $ref, string $name): void {
    $index = reachableDefsDocument(['$ref' => $ref], [
        $name => ['type' => 'object', 'properties' => ['leaf' => ['$ref' => '#/components/schemas/Leaf']]],
    ]);

    $violations = (new SchemaCheck($index))->check((object) ['leaf' => (object) ['n' => 'x']], reachableDefsSubject(), 'the body');

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->pointer)->toBe('/leaf/n')
        ->and($violations[0]->schemaPointer)->toBe('/components/schemas/Leaf/properties/n');
})->with('spellings of a reference to Middle');

it('refuses a reference to a component the document does not define, in the words it always did', function (): void {
    $check = new SchemaCheck(reachableDefsDocument(['$ref' => '#/components/schemas/Missing']));

    expect(fn () => $check->check((object) [], reachableDefsSubject(), 'the body'))
        ->toThrow(RuntimeException::class, 'Unresolved reference: /%24defs/Missing');
});

it('refuses a pointer to a place a component lacks in the words it always did, wherever the pointer stands', function (array $subject): void {
    // The component is there and the place inside it is not. Nothing the validator could be handed would
    // resolve it, so the refusal names the pointer as the document wrote it — never a document of ours,
    // whose name would move with every component declared before this one.
    $check = new SchemaCheck(reachableDefsDocument($subject, [
        'Pointing' => ['type' => 'object', 'properties' => ['x' => ['$ref' => '#/components/schemas/Leaf/properties/missing']]],
    ]));

    expect(fn () => $check->check((object) ['x' => 1], reachableDefsSubject(), 'the body'))
        ->toThrow(RuntimeException::class, 'Unresolved reference: /%24defs/Leaf/properties/missing');
})->with([
    'from the subject' => [['$ref' => '#/components/schemas/Leaf/properties/missing']],
    'from inside a component' => [['$ref' => '#/components/schemas/Pointing']],
]);

it('keeps a recursive component recursive', function (): void {
    $index = reachableDefsDocument(['$ref' => '#/components/schemas/Node'], [
        'Node' => ['type' => 'object', 'properties' => [
            'value' => ['type' => 'integer'],
            'children' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Node']],
        ]],
    ]);

    $tree = (object) ['value' => 1, 'children' => [(object) ['value' => 2, 'children' => [(object) ['value' => 'x']]]]];

    $violations = (new SchemaCheck($index))->check($tree, reachableDefsSubject(), 'the body');

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->pointer)->toBe('/children/0/children/0/value');
});
