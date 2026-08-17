<?php

declare(strict_types=1);

use Docuccino\Core\Support\Arr;
use Docuccino\Core\Support\Hydrate;

it('coerces scalars, passing valid values and nulling the rest', function (): void {
    expect(Hydrate::stringOrNull('x'))->toBe('x');
    expect(Hydrate::stringOrNull(1))->toBeNull();
    expect(Hydrate::boolOrNull(true))->toBeTrue();
    expect(Hydrate::boolOrNull('true'))->toBeNull();
    expect(Hydrate::intOrNull(7))->toBe(7);
    expect(Hydrate::intOrNull('7'))->toBeNull();
    expect(Hydrate::intOrNull(1.5))->toBeNull();
});

it('keeps only string members in a string list', function (): void {
    expect(Hydrate::stringList(['a', 1, 'b', null, 'c']))->toBe(['a', 'b', 'c']);
    expect(Hydrate::stringList('nope'))->toBe([]);
    expect(Hydrate::stringList(null))->toBe([]);
});

it('returns a list of maps, dropping non-arrays, and null when not a list', function (): void {
    expect(Hydrate::listOfMaps([['a' => 1], 'skip', ['b' => 2]]))->toBe([['a' => 1], ['b' => 2]]);
    expect(Hydrate::listOfMaps([]))->toBe([]);
    expect(Hydrate::listOfMaps('nope'))->toBeNull();
    expect(Hydrate::listOfMaps(null))->toBeNull();
});

it('reads a security member the way OAS says it is written', function (): void {
    expect(Hydrate::securityRequirements([['bearerAuth' => []]]))->toBe([['bearerAuth' => []]])
        ->and(Hydrate::securityRequirements([]))->toBe([])
        ->and(Hydrate::securityRequirements([[], ['bearerAuth' => ['read']]]))->toBe([[], ['bearerAuth' => ['read']]])
        ->and(Hydrate::securityRequirements('nope'))->toBeNull()
        ->and(Hydrate::securityRequirements(null))->toBeNull();
});

it('keeps the scheme name of a requirement written without the list around it', function (): void {
    // A bare map is malformed and unambiguous: it states one requirement. Unwrapped the way `servers` and
    // `tags` are, the names go and a document demanding a scheme reads as one demanding nothing.
    expect(Hydrate::securityRequirements(['bearerAuth' => []]))->toBe([['bearerAuth' => []]])
        ->and(Hydrate::securityRequirements(['bearerAuth' => [], 'apiKey' => ['read']]))
        ->toBe([['bearerAuth' => [], 'apiKey' => ['read']]])
        // Never an empty requirement alongside, which is how a document says credentials are optional.
        ->and(Hydrate::securityRequirements(['bearerAuth' => 'not scopes']))->toBe([['bearerAuth' => 'not scopes']])
        // And a list that also carries stray string keys keeps both halves.
        ->and(Hydrate::securityRequirements([['apiKey' => []], 'skip', 'bearerAuth' => []]))
        ->toBe([['apiKey' => []], ['bearerAuth' => []]]);
});

it('hydrates each map member of a list through the factory, dropping non-arrays', function (): void {
    $factory = static fn (array $m): string => (string) ($m['v'] ?? '');

    expect(Hydrate::listOf([['v' => 'a'], 'skip', ['v' => 'b']], $factory))->toBe(['a', 'b']);
    expect(Hydrate::listOf('nope', $factory))->toBe([]);
});

it('passes each map member into the factory in listOf', function (): void {
    $seen = Hydrate::listOf([['a' => 1, 'b' => 2]], static fn (array $m): array => array_keys($m));

    expect($seen)->toBe([['a', 'b']]);
});

it('hydrates a keyed collection through the factory, coercing keys to strings', function (): void {
    $factory = static fn (array $m): string => (string) ($m['v'] ?? '');

    expect(Hydrate::mapOf(['x' => ['v' => 'a'], 7 => ['v' => 'b'], 'z' => 'skip'], $factory))
        ->toBe(['x' => 'a', '7' => 'b']);
    expect(Hydrate::mapOf(null, $factory))->toBe([]);
});

it('hydrates a single nested map or returns null', function (): void {
    $factory = static fn (array $m): string => (string) ($m['v'] ?? '');

    expect(Hydrate::objectOrNull(['v' => 'a'], $factory))->toBe('a');
    expect(Hydrate::objectOrNull(null, $factory))->toBeNull();
    expect(Hydrate::objectOrNull('nope', $factory))->toBeNull();
});

it('takes the sorted, deduplicated string union of two arrays', function (): void {
    expect(Arr::sortedUnion(['b', 'a'], ['c', 'a']))->toBe(['a', 'b', 'c']);
    expect(Arr::sortedUnion([2, 1], [1, 3]))->toBe(['1', '2', '3']);
    expect(Arr::sortedUnion([], []))->toBe([]);
});
