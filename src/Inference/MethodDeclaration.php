<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference;

use PhpParser\Node;
use PhpParser\NodeFinder;
use ReflectionClass;
use ReflectionMethod;

/**
 * Where a reflected method is written among its file's name-resolved statements. Names only nominate
 * candidates — the declaring class and every trait it uses, under the name the method has there — and
 * reflection decides: the answer is the one candidate in the file reflection names whose `function`
 * keyword sits on the line reflection reports ({@see DeclarationLine}), or nothing. A name alone is not
 * enough, because `insteadof` picks between traits that write the same name and only PHP's resolution
 * of it, which reflection reports, says which body runs.
 *
 * @internal
 */
final class MethodDeclaration
{
    /**
     * @param  array<Node>  $statements  the name-resolved statements of `$method->getFileName()`
     */
    public static function in(array $statements, ReflectionMethod $method): ?Node\Stmt\ClassMethod
    {
        $file = $method->getFileName();
        $line = $method->getStartLine();
        $source = $file === false || ! is_file($file) ? false : file_get_contents($file);
        if ($source === false || $line === false) {
            return null;
        }

        $finder = new NodeFinder;
        $found = [];
        foreach (self::writers($method->getDeclaringClass(), $method->getName()) as [$writer, $name]) {
            if ($writer->getFileName() !== $file) {
                continue; // reflection says the body is written elsewhere
            }

            $classes = $finder->find(
                $statements,
                $writer->isAnonymous()
                    ? static fn (Node $node): bool => $node instanceof Node\Stmt\Class_ && $node->name === null
                    : static fn (Node $node): bool => $node instanceof Node\Stmt\ClassLike && $node->namespacedName?->toString() === $writer->getName(),
            );
            foreach ($classes as $class) {
                $candidate = $class instanceof Node\Stmt\ClassLike ? $class->getMethod($name) : null;
                if ($candidate !== null && DeclarationLine::of($candidate, $source) === $line) {
                    $found[spl_object_id($candidate)] = $candidate;
                }
            }
        }

        return count($found) === 1 ? reset($found) : null;
    }

    /**
     * The declaring class and every trait it uses, however deep, each with the name the method has THERE —
     * PHP reports a trait's method as the using class's, under whatever alias the `use` clause gave it, and
     * only the trait's body writes it, under its own name.
     *
     * @param  ReflectionClass<object>  $class
     * @return list<array{ReflectionClass<object>, string}>
     */
    private static function writers(ReflectionClass $class, string $name): array
    {
        $writers = [];
        $seen = [];
        /** @var list<array{ReflectionClass<object>, string}> $pending */
        $pending = [[$class, $name]];
        while ($pending !== []) {
            [$next, $as] = array_shift($pending);
            if (isset($seen[$next->getName()."\0".$as])) {
                continue;
            }
            $seen[$next->getName()."\0".$as] = true;
            $writers[] = [$next, $as];

            $traits = $next->getTraits();
            $alias = $next->getTraitAliases()[$as] ?? null;
            $split = $alias === null ? [] : explode('::', $alias, 2);
            if (count($split) === 2 && isset($traits[$split[0]])) {
                $pending[] = [$traits[$split[0]], $split[1]];
            }
            foreach ($traits as $trait) {
                $pending[] = [$trait, $as];
            }
        }

        return $writers;
    }
}
