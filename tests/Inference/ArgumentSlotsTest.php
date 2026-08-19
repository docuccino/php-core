<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Inference;

use Docuccino\Core\Inference\ArgumentSlots;
use PhpParser\Node;
use PhpParser\ParserFactory;

/**
 * The one placement rule every reader that indexes a call goes through. Each case below is a form a
 * caller may write; what matters at every one is whether "nothing in this slot" is a fact or a guess.
 *
 * @param  list<string>  $paramNames
 */
function slotsFor(string $call, array $paramNames = []): ArgumentSlots
{
    $statements = (new ParserFactory)->createForNewestSupportedVersion()->parse('<?php '.$call.';') ?? [];
    $expression = $statements[0] ?? null;

    expect($expression)->toBeInstanceOf(Node\Stmt\Expression::class);
    expect($expression->expr)->toBeInstanceOf(Node\Expr\CallLike::class);

    return ArgumentSlots::of($expression->expr->getArgs(), $paramNames);
}

/**
 * The literal string each positional slot holds, for a call written entirely in string literals.
 *
 * @return list<string>
 */
function slotStrings(ArgumentSlots $slots): array
{
    return array_map(
        static fn (Node\Expr $expr): string => $expr instanceof Node\Scalar\String_ ? $expr->value : $expr::class,
        $slots->positional(),
    );
}

it('places positional arguments under their own index', function (): void {
    $slots = slotsFor("f('a', 'b')");

    expect(slotStrings($slots))->toBe(['a', 'b'])
        ->and($slots->isIndexable())->toBeTrue()
        ->and($slots->isOpaque())->toBeFalse()
        ->and($slots->at(2))->toBeNull()
        ->and($slots->knows(2))->toBeTrue();
});

it('keeps a named argument under its name when nothing says which parameter it is', function (): void {
    // The reader can still ask for it BY NAME; what it may not do is count positions, because the name
    // may belong to any of them.
    $slots = slotsFor("f('a', name: 'b')");

    expect(slotStrings($slots))->toBe(['a'])
        ->and($slots->at('name'))->toBeInstanceOf(Node\Scalar\String_::class)
        ->and($slots->at(1))->toBeNull()
        ->and($slots->isIndexable())->toBeFalse()
        ->and($slots->isOpaque())->toBeFalse();
});

it('places a named argument at its position once the caller names the parameters', function (): void {
    $slots = slotsFor("f('a', name: 'b')", ['file', 'name']);

    expect(slotStrings($slots))->toBe(['a', 'b'])
        ->and($slots->isIndexable())->toBeTrue();
});

it('declines to index a call where a named argument skipped a position', function (): void {
    // Slot 1 was never passed, so `positional()` stops before slot 2 and would silently lose it. A reader
    // asking by key still gets both, which is the difference between the two questions.
    $slots = slotsFor("f('a', headers: 'h')", ['file', 'name', 'headers']);

    expect(slotStrings($slots))->toBe(['a'])
        ->and($slots->isIndexable())->toBeFalse()
        ->and($slots->at(2))->toBeInstanceOf(Node\Scalar\String_::class)
        ->and($slots->at(1))->toBeNull();
});

it('expands a spread the call site wrote out into the positions it takes', function (string $call, array $expected): void {
    // Nothing is hidden in a written list: its items ARE the arguments, so declining here would widen
    // away values that are right there to read.
    $slots = slotsFor($call);

    expect(slotStrings($slots))->toBe($expected)
        ->and($slots->isIndexable())->toBeTrue()
        ->and($slots->isOpaque())->toBeFalse();
})->with([
    'a whole list' => ["f(...['a', 'b'])", ['a', 'b']],
    'after a positional' => ["f('a', ...['b', 'c'])", ['a', 'b', 'c']],
    'two of them' => ["f(...['a'], ...['b'])", ['a', 'b']],
    'nested, flattened the same way' => ["f(...['a', ...['b', 'c']])", ['a', 'b', 'c']],
    'an empty one, which passes nothing' => ["f('a', ...[])", ['a']],
]);

it('reads a spread it cannot see the sequence of as covering every slot from there', function (string $call): void {
    // The spread fills its own position and every later one, so a position that looks absent may well be
    // supplied — and a keyed sequence binds parameters by NAME, so no name is knowable either.
    $slots = slotsFor($call, ['file', 'name']);

    expect($slots->isOpaque())->toBeTrue()
        ->and($slots->isIndexable())->toBeFalse()
        ->and($slots->knows(0))->toBeFalse()
        ->and($slots->knows('name'))->toBeFalse()
        ->and($slots->at(0))->toBeNull()
        ->and($slots->at('name'))->toBeNull();
})->with([
    'a variable' => ['f(...$args)'],
    'a call' => ['f(...$this->args())'],
    'a class constant' => ['f(...self::ARGS)'],
    'a keyed literal, whose keys bind by name' => ["f(...['name' => 'b'])"],
    'a literal holding a by-reference item' => ['f(...[&$a])'],
    'a literal holding a spread of its own that would not read' => ['f(...[...$args])'],
]);

it('keeps what was written before an unreadable spread, and nothing after it', function (): void {
    $slots = slotsFor("f('a', ...\$rest, name: 'b')", ['file', 'name']);

    expect(slotStrings($slots))->toBe(['a'])
        ->and($slots->knows(0))->toBeTrue()
        ->and($slots->at(0))->toBeInstanceOf(Node\Scalar\String_::class)
        ->and($slots->knows(1))->toBeFalse()
        ->and($slots->at('name'))->toBeNull();
});

it('answers with the whole slot map for a reader that indexes by position and by name', function (): void {
    $slots = slotsFor("f('a', name: 'b')");

    expect(array_keys($slots->all()))->toBe([0, 'name']);
});

it('reads a list literal as the arguments it contributes, and anything else as none', function (): void {
    $literal = (new ParserFactory)->createForNewestSupportedVersion()->parse("<?php ['a', 'b'];") ?? [];
    $variable = (new ParserFactory)->createForNewestSupportedVersion()->parse('<?php $args;') ?? [];

    expect($literal[0])->toBeInstanceOf(Node\Stmt\Expression::class)
        ->and($variable[0])->toBeInstanceOf(Node\Stmt\Expression::class);

    expect(ArgumentSlots::listItems($literal[0]->expr))->toHaveCount(2)
        ->and(ArgumentSlots::listItems($variable[0]->expr))->toBeNull();
});
