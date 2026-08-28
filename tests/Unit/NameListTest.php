<?php

declare(strict_types=1);

use Docuccino\Core\Support\NameList;

/*
 * The list a diagnostic sentence ends with. Two policies, one owner: where a list stops being read and
 * starts being scrolled, and that nothing in it reaches a terminal as the application wrote it.
 */

it('renders a list, capped, and says how many it did not name', function (array $names, ?string $expected): void {
    expect(NameList::of($names))->toBe($expected);
})->with([
    // Nothing to list is the caller's sentence to write, not this one's — "it documents none" and
    // "there are none configured" are different facts.
    'nothing' => [[], null],
    'one name' => [['id'], 'id'],
    'a list under the cap' => [['id', 'name'], 'id, name'],
    'exactly the cap' => [['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'], 'a, b, c, d, e, f, g, h'],
    'one over the cap' => [['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i'], 'a, b, c, d, e, f, g, h and 1 more'],
    'well over the cap' => [['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k'], 'a, b, c, d, e, f, g, h and 3 more'],
]);

it('escapes every name it did not write, the ones past the cap included', function (): void {
    // A name here is recovered from an application's own code — a validation rule key, a model
    // attribute — so it reaches a terminal unread.
    $listed = NameList::of(["sort\x1b[31m", "id\x07"]);

    expect($listed)->toBe('sort\\x1B[31m, id\\x07')
        ->and($listed)->not->toContain("\x1b");
});

it('names the cap it applies, so a caller quoting one cannot quote a different number', function (): void {
    $names = array_map(static fn (int $index): string => 'n'.$index, range(1, NameList::MAX + 1));

    expect(substr_count((string) NameList::of($names), ','))->toBe(NameList::MAX - 1)
        ->and(NameList::of($names))->toEndWith('and 1 more');
});
