<?php

declare(strict_types=1);

use Docuccino\Core\Inference\MethodDeclaration;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

/**
 * {@see MethodDeclaration} against the body PHP itself runs: every row calls the method and expects the
 * located body to be the one that answered. Traits resolved by `insteadof` are the rows a lookup by name
 * gets wrong, since both traits write the method under the same name in the same file.
 *
 * @return array{file: string, namespace: string}
 */
function methodDeclarationLoaded(): array
{
    static $loaded = null;
    if ($loaded === null) {
        $namespace = 'DocuccinoMethodDeclarations'.dechex(random_int(0, PHP_INT_MAX));
        $file = sys_get_temp_dir().'/docuccino-method-declarations-'.uniqid('', true).'.php';
        file_put_contents($file, <<<PHP
            <?php
            namespace $namespace;
            trait First { public function hello(): string { return 'First'; } }
            trait Second { public function hello(): string { return 'Second'; } }
            class PrefersSecond { use First, Second { Second::hello insteadof First; First::hello as fromFirst; } }
            class PrefersFirst { use First, Second { First::hello insteadof Second; Second::hello as fromSecond; } }
            class Overrides { use First; public function hello(): string { return 'Overrides'; } }
            trait Nested { use Second; }
            class Deep { use Nested; }
            trait Left { public function twin(): string { return 'Left'; } } trait Right { public function twin(): string { return 'Right'; } }
            class Tied { use Left, Right { Right::twin insteadof Left; } }
            class Anonymous { public static function make(): object { return new class { public function hello(): string { return 'Anonymous'; } }; } }
            PHP);
        require $file;
        $loaded = ['file' => $file, 'namespace' => $namespace];
    }

    return $loaded;
}

/**
 * @return array<Node>
 */
function methodDeclarationStatements(string $file): array
{
    $statements = (new ParserFactory)->createForHostVersion()->parse((string) file_get_contents($file)) ?? [];

    return (new NodeTraverser(new NameResolver))->traverse($statements);
}

it('locates the body PHP runs for a method, however traits resolve it', function (string $class, string $method): void {
    $loaded = methodDeclarationLoaded();
    $name = $loaded['namespace'].'\\'.$class;
    $instance = $class === 'Anonymous' ? $name::make() : new $name;
    $reflected = new ReflectionMethod($instance, $method);

    $node = MethodDeclaration::in(methodDeclarationStatements($loaded['file']), $reflected);

    expect($node)->not->toBeNull()
        ->and((new Standard)->prettyPrint($node?->stmts ?? []))->toBe("return '".$instance->{$method}()."';");
})->with([
    // `insteadof` picks the second trait of the `use` list; a walk in list order finds the first.
    'insteadof the trait listed first' => ['PrefersSecond', 'hello'],
    'insteadof the trait listed second' => ['PrefersFirst', 'hello'],
    'the excluded trait under an alias' => ['PrefersSecond', 'fromFirst'],
    'the other excluded trait under an alias' => ['PrefersFirst', 'fromSecond'],
    'the class overriding its trait' => ['Overrides', 'hello'],
    'a trait used by a trait' => ['Deep', 'hello'],
    'an anonymous class' => ['Anonymous', 'hello'],
]);

it('answers nothing where the reflected line holds two candidates', function (): void {
    // Two traits on one line writing one name: a line is all reflection gives, so neither is certain.
    $loaded = methodDeclarationLoaded();
    $reflected = new ReflectionMethod($loaded['namespace'].'\\Tied', 'twin');

    expect(MethodDeclaration::in(methodDeclarationStatements($loaded['file']), $reflected))->toBeNull();
});

it('answers nothing from statements of a file that does not write the method', function (): void {
    $loaded = methodDeclarationLoaded();
    $reflected = new ReflectionMethod($loaded['namespace'].'\\PrefersSecond', 'hello');

    expect(MethodDeclaration::in(methodDeclarationStatements(__FILE__), $reflected))->toBeNull();
});
