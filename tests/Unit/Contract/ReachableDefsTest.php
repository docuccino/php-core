<?php

declare(strict_types=1);

use Docuccino\Core\Contract\ReachableDefs;
use Opis\JsonSchema\Parsers\Drafts\Draft06;
use Opis\JsonSchema\Parsers\Drafts\Draft07;
use Opis\JsonSchema\Parsers\Drafts\Draft201909;
use Opis\JsonSchema\Parsers\Drafts\Draft202012;
use Opis\JsonSchema\Parsers\Keywords\RefKeywordParser;

/*
 * What a check may leave behind is whatever no `$ref` can reach, so each spelling below is what the
 * validator itself resolves the reference to: a pointer segment is percent-decoded and then `~1`/`~0`
 * unescaped, which is why `%24defs` is the `$defs` member and `A~1B` is the component `A/B`.
 */
dataset('pointer references', [
    'a member of $defs' => ['#/$defs/A', ['A']],
    'a pointer into one' => ['#/$defs/A/properties/x', ['A']],
    'an escaped slash' => ['#/$defs/A~1B', ['A/B']],
    'an escaped tilde' => ['#/$defs/A~0B', ['A~B']],
    'a percent-encoded name' => ['#/$defs/A%20B', ['A B']],
    'a percent-encoded $defs' => ['#/%24defs/A', ['A']],
    'the root' => ['#', []],
    'the root as a pointer' => ['#/', []],
    'the subject itself' => ['#/properties/x', []],
]);

it('reads a pointer reference as the $defs member it resolves to', function (string $ref, array $names): void {
    expect(ReachableDefs::of((object) ['$ref' => $ref]))->toBe($names);
})->with('pointer references');

dataset('references it cannot follow', [
    '$defs itself' => ['#/$defs'],
    'an anchor' => ['#leaf'],
    'another document' => ['other.json#/$defs/A'],
    'an absolute URI' => ['https://example.com/schemas/a.json'],
    'a relative JSON pointer' => ['0/properties/a'],
    'a template' => ['#/$defs/{name}'],
    'nothing at all' => [''],
]);

it('gives up on every reference that is not a plain pointer from the root', function (string $ref): void {
    expect(ReachableDefs::of((object) ['$ref' => $ref]))->toBeNull();
})->with('references it cannot follow');

it('gives up on a $ref that is not a string', function (): void {
    expect(ReachableDefs::of((object) ['$ref' => 42]))->toBeNull();
});

/*
 * The members that name a schema without a pointer, read off the validator's own grammar rather than
 * listed: each draft's `$ref` parser declares the dynamic and recursive variations it resolves, and the
 * parser reads `$id`, `$anchor` and `$schema` directly. A variation a new release adds would otherwise
 * be one the reachability read steps straight past.
 */
dataset('addressing members', function (): array {
    $members = ['$id', '$anchor', '$schema'];

    foreach ([Draft06::class, Draft07::class, Draft201909::class, Draft202012::class] as $draft) {
        $parser = (new ReflectionMethod($draft, 'getRefKeywordParser'))->invoke(new $draft);
        assert($parser instanceof RefKeywordParser);

        foreach ((new ReflectionProperty(RefKeywordParser::class, 'variations'))->getValue($parser) ?? [] as $variation) {
            $members[] = $variation['ref'];
            $members[] = $variation['anchor'];
        }
    }

    $members = array_values(array_unique($members));

    // Four variation members exist today; reading none would mean the grammar moved, not that it shrank.
    expect(count($members))->toBeGreaterThanOrEqual(7);

    return array_combine($members, array_map(static fn (string $m): array => [$m], $members));
});

it('gives up on a schema carrying any member that names a schema another way', function (string $member): void {
    $schema = (object) [
        'type' => 'object',
        'properties' => (object) ['nested' => (object) [$member => 'x', 'type' => 'string']],
    ];

    expect(ReachableDefs::of($schema))->toBeNull();
})->with('addressing members');

it('collects every reference however deep, lists and objects alike', function (): void {
    $schema = (object) [
        'oneOf' => [(object) ['$ref' => '#/$defs/A'], (object) ['items' => (object) ['$ref' => '#/$defs/B']]],
        'properties' => (object) ['c' => (object) ['$ref' => '#/$defs/C']],
    ];

    expect(ReachableDefs::of($schema))->toEqualCanonicalizing(['A', 'B', 'C']);
});

it('follows every def a reached def reaches, and nothing else', function (): void {
    $reaches = ['A' => ['B'], 'B' => ['C', 'A'], 'C' => [], 'D' => ['A']];

    expect(ReachableDefs::closure(['A'], reachesFrom($reaches)))->toBe(['A' => true, 'B' => true, 'C' => true])
        ->and(ReachableDefs::closure([], reachesFrom($reaches)))->toBe([]);
});

it('asks about the defs it reaches and no other, each once', function (): void {
    // What lets a caller read a def the first time one is reached rather than all of them up front.
    $asked = [];
    $reaches = reachesFrom(['A' => ['B'], 'B' => ['A', 'C'], 'C' => ['B'], 'D' => ['A']]);

    ReachableDefs::closure(['A', 'A'], static function (string $name) use (&$asked, $reaches): array|false|null {
        $asked[] = $name;

        return $reaches($name);
    });

    expect($asked)->toEqualCanonicalizing(['A', 'B', 'C']);
});

it('skips a name no def holds, since that reference resolves to nothing whatever travels', function (): void {
    expect(ReachableDefs::closure(['Missing', 'C'], reachesFrom(['C' => []])))->toBe(['C' => true]);
});

it('gives up on the whole closure the moment a reached def cannot be read', function (): void {
    $reaches = ['A' => ['B'], 'B' => null, 'C' => []];

    expect(ReachableDefs::closure(['A'], reachesFrom($reaches)))->toBeNull()
        ->and(ReachableDefs::closure(['C'], reachesFrom($reaches)))->toBe(['C' => true])
        ->and(ReachableDefs::closure(null, reachesFrom($reaches)))->toBeNull();
});

it('reads a pointer into the root outside $defs as unreadable for a schema stored among other $defs', function (): void {
    // In a root the schema is the rest of, `#/properties/x` is the schema itself and names nothing to add;
    // stored as one `$def` beside others, the root is not the schema, so it cannot say what that names.
    expect(ReachableDefs::of((object) ['$ref' => '#/properties/x']))->toBe([])
        ->and(ReachableDefs::within((object) ['$ref' => '#/properties/x']))->toBeNull()
        ->and(ReachableDefs::within((object) ['$ref' => '#']))->toBeNull()
        ->and(ReachableDefs::within((object) ['$ref' => '#/$defs/A']))->toBe(['A'])
        ->and(ReachableDefs::within((object) ['$ref' => 'https://example.com/a.json']))->toBeNull();
});

it('names the $defs member a pointer into $defs names, and nothing for any other reference', function (string $ref, ?string $name): void {
    expect(ReachableDefs::defNamed($ref))->toBe($name);
})->with([
    ['#/$defs/A', 'A'],
    ['#/%24defs/A/properties/x', 'A'],
    ['#/$defs/A~1B', 'A/B'],
    ['#/$defs', null],
    ['#/properties/x', null],
    ['#', null],
    ['#anchor', null],
    ['other.json#/$defs/A', null],
]);

/*
 * A `$ref` counts only where the validator follows one, which is at a keyword of a schema. Inside a value
 * a schema states it is part of that value, and under a map of names a `$ref`, a `default` or an `x-`
 * key is a name like any other — holding a schema whose own references count.
 */
dataset('references inside a value a schema states', [
    'a const' => [['const' => ['$ref' => '#/$defs/A']]],
    'an enum' => [['enum' => [['$ref' => '#/$defs/A']]]],
    'a default' => [['default' => ['$ref' => '#/$defs/A']]],
    'an example' => [['example' => ['$ref' => '#/$defs/A']]],
    'examples' => [['examples' => [['$ref' => '#/$defs/A']]]],
    'an extension' => [['x-thing' => ['$ref' => '#/$defs/A']]],
    // Counted, either of these would give up on the whole schema rather than name one def too many.
    'a value naming another document' => [['const' => ['$ref' => 'other.json#/x']]],
    'a value naming the root' => [['properties' => ['a' => ['example' => ['$ref' => '#/properties/a']]]]],
]);

it('counts no $ref inside a value a schema states, which is a value and not a reference', function (array $schema): void {
    $graph = json_decode((string) json_encode($schema), false);

    expect(ReachableDefs::of($graph))->toBe([])
        ->and(ReachableDefs::within($graph))->toBe([]);
})->with('references inside a value a schema states');

dataset('references under a name spelled like a keyword', [
    'a property called default' => [['properties' => ['default' => ['$ref' => '#/$defs/A']]]],
    'a property called $ref' => [['properties' => ['$ref' => ['$ref' => '#/$defs/A']]]],
    'a pattern called const' => [['patternProperties' => ['const' => ['$ref' => '#/$defs/A']]]],
    'a $defs entry called enum' => [['$defs' => ['enum' => ['$ref' => '#/$defs/A']]]],
    'a definitions entry called examples' => [['definitions' => ['examples' => ['$ref' => '#/$defs/A']]]],
    'a dependent schema called example' => [['dependentSchemas' => ['example' => ['$ref' => '#/$defs/A']]]],
    'a dependency called x-thing' => [['dependencies' => ['x-thing' => ['$ref' => '#/$defs/A']]]],
]);

it('counts a $ref under a name spelled like a keyword, which is a name like any other', function (array $schema): void {
    $graph = json_decode((string) json_encode($schema), false);

    expect(ReachableDefs::of($graph))->toBe(['A'])
        ->and(ReachableDefs::within($graph))->toBe(['A']);
})->with('references under a name spelled like a keyword');

it('gives up on a member naming a schema another way even inside a value, where the validator registers it too', function (array $schema): void {
    // The validator walks every member of what it is handed before it validates, data included, and
    // registers each id and anchor it passes — so one inside an example claims a name all the same.
    expect(ReachableDefs::of(json_decode((string) json_encode($schema), false)))->toBeNull();
})->with([
    'an id inside an example' => [['example' => ['$id' => 'https://example.com/a']]],
    'an anchor inside a const' => [['const' => ['$anchor' => 'a']]],
    'an id inside an extension' => [['x-thing' => ['$id' => 'https://example.com/a']]],
]);

it('answers null for a complete closure the moment a name lands nowhere', function (): void {
    $reaches = reachesFrom(['A' => ['B'], 'B' => [], 'C' => ['Missing']]);

    expect(ReachableDefs::closure(['A'], $reaches, complete: true))->toBe(['A' => true, 'B' => true])
        ->and(ReachableDefs::closure(['C'], $reaches, complete: true))->toBeNull()
        ->and(ReachableDefs::closure(['Missing'], $reaches, complete: true))->toBeNull()
        ->and(ReachableDefs::closure(['C'], $reaches))->toBe(['C' => true]);
});
