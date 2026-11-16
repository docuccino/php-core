<?php

declare(strict_types=1);

use Docuccino\Core\Inference\CallCondition;
use Docuccino\Core\Inference\ConditionTree;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeCondition;

/**
 * A guard read in three values. PHP's own `||`, `&&` and `!` decide every row where both leaves are answered,
 * so those rows are checked against PHP itself; a row with an unanswered leaf is false only where PHP gives
 * false for BOTH answers that leaf could still turn out to have, which is what keeps a path a guard might
 * take from being ruled out.
 */
it('answers every operator the way PHP does, and false only where every unanswered leaf agrees', function (string $op): void {
    $a = ConditionTree::leaf(new CallCondition('request', 'is', ['api/*'], true));
    $b = ConditionTree::leaf(new TypeCondition('response', 'Illuminate\\Http\\JsonResponse', true));

    $php = static fn (bool $x, bool $y): bool => match ($op) {
        'any' => $x || $y,
        'all' => $x && $y,
        'not' => ! $x,
    };
    $tree = match ($op) {
        'any' => ConditionTree::any([$a, $b]),
        'all' => ConditionTree::all([$a, $b]),
        'not' => ConditionTree::not($a),
    };

    foreach ([true, false, null] as $x) {
        foreach ([true, false, null] as $y) {
            $outcomes = [];
            foreach ($x === null ? [true, false] : [$x] as $xx) {
                foreach ($y === null ? [true, false] : [$y] as $yy) {
                    $outcomes[] = $php($xx, $yy);
                }
            }
            $expected = count(array_unique($outcomes)) === 1 ? $outcomes[0] : null;

            $answer = $tree->evaluate(static fn (CallCondition|TypeCondition $leaf): ?bool => $leaf instanceof CallCondition ? $x : $y);

            expect($answer)->toBe($expected, sprintf('%s(%s, %s)', $op, var_export($x, true), var_export($y, true)));
        }
    }
})->with(['any', 'all', 'not']);

it('settles what its known members decide, and keeps what they do not', function (): void {
    $leaf = ConditionTree::leaf(new CallCondition('request', 'is', ['api/*'], true));
    $unknown = ConditionTree::known(null);

    expect(ConditionTree::any([$leaf, ConditionTree::known(true)])->toArray())->toBe(['known' => true])
        ->and(ConditionTree::all([$leaf, ConditionTree::known(false)])->toArray())->toBe(['known' => false])
        // A member that cannot change the answer drops out, and one left stands for the whole.
        ->and(ConditionTree::any([$leaf, ConditionTree::known(false)])->toArray())->toBe($leaf->toArray())
        ->and(ConditionTree::all([$leaf, ConditionTree::known(true)])->toArray())->toBe($leaf->toArray())
        ->and(ConditionTree::all([])->toArray())->toBe(['known' => true])
        ->and(ConditionTree::any([])->toArray())->toBe(['known' => false])
        ->and(ConditionTree::not(ConditionTree::not($leaf))->toArray())->toBe($leaf->toArray())
        ->and(ConditionTree::not($unknown)->toArray())->toBe(['known' => null])
        // Nested alike, one level: `a && (b && c)` is `a && b && c`.
        ->and(ConditionTree::all([$leaf, ConditionTree::all([$unknown, $leaf])])->toArray())->toBe(['all' => [$leaf->toArray(), ['known' => null], $leaf->toArray()]])
        // Only a guard that can still come out false rules anything out.
        ->and(ConditionTree::known(true)->constrains())->toBeFalse()
        ->and($unknown->constrains())->toBeFalse()
        ->and(ConditionTree::known(false)->constrains())->toBeTrue()
        ->and(ConditionTree::any([$leaf, $unknown])->constrains())->toBeTrue();
});

it('replaces the leaves it is answered for and leaves the rest', function (): void {
    $api = ConditionTree::leaf(new CallCondition('request', 'is', ['api/*'], true));
    $handoff = ConditionTree::leaf(new TypeCondition('e', 'Illuminate\\Http\\Exceptions\\HttpResponseException', true));
    $guard = ConditionTree::any([ConditionTree::not($api), $handoff]);

    $answered = $guard->answered(static fn (CallCondition|TypeCondition $leaf): ?bool => $leaf instanceof TypeCondition ? false : null);

    expect($answered->toArray())->toBe(['not' => $api->toArray()]);
});

it('round-trips through its array form, and reads a malformed one as unknown', function (): void {
    $tree = ConditionTree::all([
        ConditionTree::any([
            ConditionTree::not(ConditionTree::leaf(new TypeCondition('response', 'Illuminate\\Http\\JsonResponse', true))),
            ConditionTree::leaf(new CallCondition('response', 'getStatusCode', [], 419)),
        ]),
        ConditionTree::known(null),
        ConditionTree::leaf(new CallCondition('request', 'is', ['api/*'], true)),
    ]);
    $payload = $tree->toArray();

    expect(ConditionTree::fromArray($payload)->toArray())->toBe($payload)
        ->and(ConditionTree::fromArray(['what' => 1])->toArray())->toBe(['known' => null])
        ->and(ConditionTree::fromArray(['not' => 'x'])->toArray())->toBe(['known' => null])
        ->and(ConditionTree::fromArray(['any' => ['x', ['known' => false]]])->toArray())->toBe(['known' => false]);
});

it('travels on a return site, and onto a declaration made further out', function (): void {
    $guard = ConditionTree::any([
        ConditionTree::not(ConditionTree::leaf(new TypeCondition('response', 'Illuminate\\Http\\JsonResponse', true))),
        ConditionTree::leaf(new TypeCondition('e', 'RuntimeException', true)),
    ]);
    $site = new ReturnSite(ScalarT::string(), new SourceLocation('/app/x.php', 3), returnsParameter: 'response', reachedWhen: $guard);

    expect(ReturnSite::fromArray($site->toArray())->toArray())->toBe($site->toArray())
        ->and($site->toArray()['reachedWhen'] ?? null)->toBe($guard->toArray())
        ->and($site->withComponent(null)->reachedWhen)->toBe($guard);
});
