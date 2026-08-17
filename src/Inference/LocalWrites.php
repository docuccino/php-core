<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference;

use PhpParser\Node;

/**
 * Which local variables one AST node writes — the single grammar every reader that folds a variable back
 * to what it holds asks, so a form one of them retires can never be a form another still trusts.
 *
 * Only a plain `$x = <expr>` HAS an expression to serve ({@see assignment()}). Every other write says
 * nothing except that the variable no longer names what it did, which is {@see retires()}: compound and
 * reference assignment, increment/decrement, `list()`/`[…]` destructuring however nested or keyed, a
 * `foreach` value AND key binding, `static`/`global`, `unset()`, and a `catch` binding. A write that names
 * no single local — through a variable variable, or `extract()` — retires the whole scope
 * ({@see retiresEveryLocal()}), since which local it landed on is a runtime fact.
 *
 * The one write it cannot see is a callee assigning through a by-reference parameter, which no expression
 * shows and only reflection on that callee can answer; a reader holding that reflection owes the check
 * itself. Writing THROUGH a local (`$body['k'] = …`, `$dto->k = …`) is deliberately not a write of the
 * local: the variable still names the same array or object it was assigned.
 *
 * @internal
 */
final class LocalWrites
{
    /**
     * The one form that carries a value, as `[name, what was assigned]`.
     *
     * @return array{string, Node\Expr}|null
     */
    public static function assignment(Node $node): ?array
    {
        if ($node instanceof Node\Expr\Assign
            && $node->var instanceof Node\Expr\Variable
            && is_string($node->var->name)
        ) {
            return [$node->var->name, $node->expr];
        }

        return null;
    }

    /**
     * Every local this node writes without one expression to speak for it.
     *
     * @return list<string>
     */
    public static function retires(Node $node): array
    {
        if (self::assignment($node) !== null) {
            return []; // the plain form is recorded, not retired — the caller decides on a second write
        }

        $names = [];
        foreach (self::targets($node) as $target) {
            $names = [...$names, ...self::namesIn($target)];
        }

        return array_values(array_unique($names));
    }

    /**
     * A write whose target no expression names: `$$key = …` or `extract($data)` lands on a local the code
     * only knows at runtime, so every local in that scope stops being trustworthy.
     */
    public static function retiresEveryLocal(Node $node): bool
    {
        if ($node instanceof Node\Expr\FuncCall
            && $node->name instanceof Node\Name
            && strtolower($node->name->toString()) === 'extract'
        ) {
            return true;
        }

        foreach (self::targets($node) as $target) {
            if (self::namesDynamically($target)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every expression this node writes to. One dispatch, so {@see retires()} and
     * {@see retiresEveryLocal()} cannot come to read different grammars.
     *
     * @return list<Node>
     */
    private static function targets(Node $node): array
    {
        // A reference binds two names to one value, so a later write through either moves both.
        if ($node instanceof Node\Expr\AssignRef) {
            return [$node->var, $node->expr];
        }

        if ($node instanceof Node\Expr\Assign
            || $node instanceof Node\Expr\AssignOp
            || $node instanceof Node\Expr\PreInc
            || $node instanceof Node\Expr\PreDec
            || $node instanceof Node\Expr\PostInc
            || $node instanceof Node\Expr\PostDec
        ) {
            return [$node->var];
        }

        if ($node instanceof Node\Stmt\Foreach_) {
            return $node->keyVar === null ? [$node->valueVar] : [$node->keyVar, $node->valueVar];
        }

        if ($node instanceof Node\Stmt\Static_) {
            return array_values(array_map(static fn (Node\Stmt\StaticVar $var): Node => $var->var, $node->vars));
        }

        if ($node instanceof Node\Stmt\Global_ || $node instanceof Node\Stmt\Unset_) {
            return array_values($node->vars);
        }

        if ($node instanceof Node\Stmt\Catch_) {
            return $node->var === null ? [] : [$node->var];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private static function namesIn(Node $target): array
    {
        if ($target instanceof Node\Expr\Variable) {
            return is_string($target->name) ? [$target->name] : [];
        }

        // `[$a, 'k' => $b] = …` and its `list()` spelling: the KEYS are read, only the values written.
        $names = [];
        foreach (self::destructuredItems($target) as $item) {
            $names = [...$names, ...self::namesIn($item)];
        }

        return $names;
    }

    private static function namesDynamically(Node $target): bool
    {
        if ($target instanceof Node\Expr\Variable) {
            return ! is_string($target->name);
        }

        foreach (self::destructuredItems($target) as $item) {
            if (self::namesDynamically($item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The written halves of a destructuring target, in either spelling; empty for anything else, including
     * a write THROUGH a local.
     *
     * @return list<Node\Expr>
     */
    private static function destructuredItems(Node $target): array
    {
        if (! $target instanceof Node\Expr\List_ && ! $target instanceof Node\Expr\Array_) {
            return [];
        }

        $items = [];
        foreach ($target->items as $item) {
            if ($item !== null) {
                $items[] = $item->value;
            }
        }

        return $items;
    }
}
