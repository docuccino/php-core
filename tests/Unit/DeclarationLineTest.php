<?php

declare(strict_types=1);

use Docuccino\Core\Inference\DeclarationLine;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/**
 * {@see DeclarationLine} against what native reflection itself reports, over declarations whose attribute,
 * modifier or comment sits on a line before the keyword — one row for every token that may stand there.
 * The reflected line is the contract: it is the only line a closure route or a callback arrives with.
 */
function declarationLineSource(): string
{
    return <<<'PHP'
        <?php
        namespace DocuccinoDeclarationLines;
        abstract class Declared {
            #[\Deprecated]
            function attributed() {}
            public
            function whitespaced() {}
            protected
            function guarded() {}
            private
            function hidden() {}
            abstract
            public function unwritten();
            final
            public function sealed() {}
            public static
            function shared() {}
            public // a comment
            function commented() {}
            #[\Deprecated] /** a docblock */
            function documented() {}
        }
        return [
            'closure' => #[\Deprecated]
                function () {},
            'static' => static
                fn () => 1,
            'commented' => static /* a comment */
                fn () => 2,
            'plain' => fn () => 3,
        ];
        PHP;
}

/**
 * The source written to a file of its own and loaded, so reflection answers for it exactly as it does
 * for an application's code.
 *
 * @return array{source: string, closures: array<string, Closure>, namespace: string}
 */
function declarationLineLoaded(): array
{
    static $loaded = null;
    if ($loaded === null) {
        $namespace = 'DocuccinoDeclarationLines'.dechex(random_int(0, PHP_INT_MAX));
        $source = str_replace('DocuccinoDeclarationLines', $namespace, declarationLineSource());
        $file = sys_get_temp_dir().'/docuccino-declaration-lines-'.uniqid('', true).'.php';
        file_put_contents($file, $source);
        /** @var array<string, Closure> $closures */
        $closures = require $file;
        $loaded = ['source' => $source, 'closures' => $closures, 'namespace' => $namespace];
    }

    return $loaded;
}

/**
 * @return list<Node\FunctionLike>
 */
function declarationLineNodes(string $source): array
{
    $statements = (new ParserFactory)->createForHostVersion()->parse($source) ?? [];

    return array_values(array_filter(
        (new NodeFinder)->find($statements, static fn (Node $node): bool => $node instanceof Node\FunctionLike),
        static fn (Node $node): bool => $node instanceof Node\FunctionLike,
    ));
}

it('names the line reflection gives a method, whatever stands before its keyword', function (string $method): void {
    $loaded = declarationLineLoaded();
    $node = null;
    foreach (declarationLineNodes($loaded['source']) as $candidate) {
        if ($candidate instanceof Node\Stmt\ClassMethod && $candidate->name->toString() === $method) {
            $node = $candidate;
        }
    }

    $reflected = (new ReflectionMethod($loaded['namespace'].'\\Declared', $method))->getStartLine();

    expect($node)->not->toBeNull()
        ->and($node?->getStartLine())->toBeLessThan($reflected)
        ->and(DeclarationLine::of($node ?? throw new RuntimeException, $loaded['source']))->toBe($reflected);
})->with([
    'an attribute' => ['attributed'],
    'public' => ['whitespaced'],
    'protected' => ['guarded'],
    'private' => ['hidden'],
    'abstract' => ['unwritten'],
    'final' => ['sealed'],
    'static' => ['shared'],
    'a comment' => ['commented'],
    'a docblock after an attribute' => ['documented'],
]);

it('names the line reflection gives a closure or an arrow function', function (string $key, int $index): void {
    $loaded = declarationLineLoaded();
    $closures = array_values(array_filter(
        declarationLineNodes($loaded['source']),
        static fn (Node\FunctionLike $node): bool => $node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction,
    ));

    expect(DeclarationLine::of($closures[$index], $loaded['source']))
        ->toBe((new ReflectionFunction($loaded['closures'][$key]))->getStartLine());
})->with([
    'a closure with an attribute above it' => ['closure', 0],
    'static on the line above' => ['static', 1],
    'a comment after static' => ['commented', 2],
    // Where nothing precedes the keyword, the node's own start line was already the answer.
    'nothing before the keyword' => ['plain', 3],
]);

it('says nothing about a node the source it is handed does not hold', function (): void {
    $loaded = declarationLineLoaded();
    $node = declarationLineNodes($loaded['source'])[0];

    // Text too short to reach the node, and text where the node's span holds no keyword at all.
    expect(DeclarationLine::of($node, '<?php '))->toBeNull()
        ->and(DeclarationLine::of($node, str_repeat(' ', strlen($loaded['source']))))->toBeNull()
        ->and(DeclarationLine::of(new Node\Expr\Closure, $loaded['source']))->toBeNull();
});
