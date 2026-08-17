<?php

declare(strict_types=1);

use Docuccino\Core\Inference\LocalWrites;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/**
 * The one grammar both halves of the stack read for "what does this node write?". Every entry of the
 * dispatch is covered from both sides — which name it retires, and that the plain `=` is the only form
 * carrying an expression — plus the unlisted forms, which must write nothing at all.
 */

/**
 * The first node of a snippet that writes something, as `[assignment, retired, opaque]`.
 *
 * @return array{array{string, Node\Expr}|null, list<string>, bool}
 */
function localWritesOf(string $code): array
{
    $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse('<?php '.$code) ?? [];

    $assignment = null;
    $retired = [];
    $opaque = false;
    foreach ((new NodeFinder)->find($ast, static fn (Node $node): bool => true) as $node) {
        $assignment ??= LocalWrites::assignment($node);
        $retired = [...$retired, ...LocalWrites::retires($node)];
        $opaque = $opaque || LocalWrites::retiresEveryLocal($node);
    }

    return [$assignment, array_values(array_unique($retired)), $opaque];
}

it('reads the plain assignment as the one form with an expression to serve', function (): void {
    [$assignment, $retired, $opaque] = localWritesOf('$size = 15;');

    expect($assignment)->toBeArray()
        ->and($assignment[0])->toBe('size')
        ->and($assignment[1])->toBeInstanceOf(Node\Scalar\Int_::class)
        ->and($retired)->toBe([])
        ->and($opaque)->toBeFalse();
});

it('retires the local on every other form that writes one', function (string $code, array $names): void {
    [$assignment, $retired, $opaque] = localWritesOf($code);

    expect($assignment)->toBeNull()
        ->and($retired)->toEqualCanonicalizing($names)
        ->and($opaque)->toBeFalse();
})->with([
    'a compound assignment' => ['$size *= 2;', ['size']],
    'a coalescing assignment' => ['$size ??= 2;', ['size']],
    'a concatenating assignment' => ['$size .= "0";', ['size']],
    // Both names: a reference makes one value of two, so a later write through either moves both.
    'a reference assignment' => ['$size = &$other;', ['size', 'other']],
    'a post-increment' => ['$size++;', ['size']],
    'a pre-increment' => ['++$size;', ['size']],
    'a post-decrement' => ['$size--;', ['size']],
    'a pre-decrement' => ['--$size;', ['size']],
    'list destructuring' => ['list($a, $b) = $pair;', ['a', 'b']],
    'short destructuring' => ['[$a, $b] = $pair;', ['a', 'b']],
    'nested destructuring' => ['[[$a], [$b, $c]] = $pairs;', ['a', 'b', 'c']],
    // A key is READ, so only the value half is a write.
    'keyed destructuring' => ['["size" => $a] = $row;', ['a']],
    'a skipped destructuring slot' => ['[, $b] = $pair;', ['b']],
    'a foreach value binding' => ['foreach ($rows as $row) {}', ['row']],
    'a foreach key and value' => ['foreach ($rows as $key => $row) {}', ['key', 'row']],
    'a destructuring foreach' => ['foreach ($rows as [$a, $b]) {}', ['a', 'b']],
    'a static declaration' => ['static $size = 1;', ['size']],
    'a global declaration' => ['global $size, $other;', ['size', 'other']],
    'an unset' => ['unset($size, $other);', ['size', 'other']],
    'a catch binding' => ['try { x(); } catch (RuntimeException $e) {}', ['e']],
]);

it('writes nothing for the forms that only write THROUGH a local, or not at all', function (string $code): void {
    [$assignment, $retired, $opaque] = localWritesOf($code);

    expect($assignment)->toBeNull()
        ->and($retired)->toBe([])
        ->and($opaque)->toBeFalse();
})->with([
    // The variable still names the same array or object it was assigned.
    'an array append' => ['$body[] = 1;'],
    'an array member' => ['$body["k"] = 1;'],
    'a property write' => ['$dto->k = 1;'],
    'a static property write' => ['Registry::$k = 1;'],
    // A catch with no binding names nothing.
    'a bindingless catch' => ['try { x(); } catch (RuntimeException) {}'],
    // Reads, however deep.
    'a call' => ['f($size, $other);'],
    'a comparison' => ['$size === 15;'],
]);

it('retires every local where the write names none of them', function (string $code): void {
    [, , $opaque] = localWritesOf($code);

    expect($opaque)->toBeTrue();
})->with([
    // Which local a variable variable lands on is a runtime fact, in either spelling.
    'a variable variable' => ['$$name = 1;'],
    'a braced variable variable' => ['${$name} = 1;'],
    'a variable variable in a list' => ['[$a, $$name] = $pair;'],
    'a variable variable foreach' => ['foreach ($rows as $$name) {}'],
    // `extract()` writes one local per key of an array nobody here can enumerate.
    'an extract' => ['extract($data);'],
    'a namespaced extract spelling' => ['\extract($data);'],
]);
