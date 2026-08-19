<?php

declare(strict_types=1);

namespace Docuccino\Core\Inference;

use PhpParser\Node;

/**
 * One call's arguments placed where a reader indexes them: a positional argument under its 0-based
 * position, a named one under its parameter name. Every reader that wants argument N asks here, so
 * "which value is in slot N" has one answer across the engine and the adapter.
 *
 * A named argument holds a position too wherever the caller can say which parameter it names; without
 * that it stays under its name, where a reader that knows the signature can still find it and one that
 * only counts positions is told the call is not indexable.
 *
 * A spread occupies no position of its own — it fills its own and every later one from a sequence it
 * carries. Where the call site wrote that sequence out as a plain list, its items ARE the arguments and
 * are expanded into the positions they really take; where it did not, every position from there on is
 * OPAQUE. {@see at()} answers null for an opaque position exactly as it does for an absent one, and
 * {@see knows()} is what tells them apart — a reader that reads "not there" as "the parameter's default"
 * must ask first, or it publishes a default the call never took.
 */
final readonly class ArgumentSlots
{
    /**
     * @param  array<array-key, Node\Expr>  $slots  position or parameter name → the expression written there
     * @param  int|null  $opaqueFrom  the first position nothing can be said about
     */
    private function __construct(
        private array $slots,
        private ?int $opaqueFrom,
        private bool $named,
    ) {}

    /**
     * @param  array<Node\Arg>  $args  a call's arguments, in written order
     * @param  list<string>  $paramNames  the callee's parameters in declaration order, where the caller
     *                                    knows them: a named argument written for one of these takes its
     *                                    position, so naming an argument does not hide it
     */
    public static function of(array $args, array $paramNames = []): self
    {
        $slots = [];
        $position = 0;
        $named = false;

        foreach ($args as $arg) {
            if ($arg->unpack) {
                $written = self::listItems($arg->value);
                if ($written === null) {
                    return new self($slots, $position, $named);
                }

                foreach ($written as $item) {
                    $slots[$position++] = $item;
                }

                continue;
            }

            if ($arg->name instanceof Node\Identifier) {
                $name = $arg->name->toString();
                $declared = array_search($name, $paramNames, true);
                // PHP forbids a positional argument after a named one, and forbids naming a parameter
                // already filled positionally, so a declared position can never be one this loop took.
                if ($declared === false) {
                    $named = true;
                    $slots[$name] = $arg->value;
                } else {
                    $slots[$declared] = $arg->value;
                }

                continue;
            }

            $slots[$position++] = $arg->value;
        }

        return new self($slots, null, $named);
    }

    /**
     * The expressions a spread contributes where the call site wrote them out: a list literal, no keys
     * and nothing passed by reference, nested spreads flattened the same way. Null for every other form —
     * a variable, a call, a constant — whose sequence is not written here to be read, and null for a keyed
     * literal, whose string keys bind parameters by NAME rather than by position.
     *
     * @return list<Node\Expr>|null
     */
    public static function listItems(Node\Expr $expr): ?array
    {
        if (! $expr instanceof Node\Expr\Array_) {
            return null;
        }

        $items = [];
        foreach ($expr->items as $item) {
            if ($item->key !== null || $item->byRef) {
                return null;
            }

            if (! $item->unpack) {
                $items[] = $item->value;

                continue;
            }

            $nested = self::listItems($item->value);
            if ($nested === null) {
                return null;
            }
            $items = [...$items, ...$nested];
        }

        return $items;
    }

    /** The expression in a slot, or null where the call wrote nothing there — or nothing readable. */
    public function at(int|string $key): ?Node\Expr
    {
        return $this->knows($key) ? ($this->slots[$key] ?? null) : null;
    }

    /**
     * Whether "absent" is a real answer for this slot. False once an unreadable spread covers it: the
     * value may well have been supplied, and a reader falling back to a default would publish one the
     * call never took. Names are unknowable there too — a spread of a keyed array binds by name.
     */
    public function knows(int|string $key): bool
    {
        if ($this->opaqueFrom === null) {
            return true;
        }

        return is_int($key) && $key < $this->opaqueFrom;
    }

    /** True while some argument sits somewhere no slot can name it — the tail past an unreadable spread. */
    public function isOpaque(): bool
    {
        return $this->opaqueFrom !== null;
    }

    /**
     * Whether {@see positional()} is the whole call: nothing left under a name, nothing past an unreadable
     * spread, and no gap where an optional parameter was skipped over by a named one. The gate for a
     * reader that will go on to treat the arguments as a plain list, where a value not in that list reads
     * as one the call never passed.
     */
    public function isIndexable(): bool
    {
        return $this->opaqueFrom === null && ! $this->named && count($this->slots) === count($this->positional());
    }

    /**
     * The positional slots in order, stopping at the first position the call left empty or unreadable.
     * A named argument is in here once it was placed, and never otherwise.
     *
     * @return list<Node\Expr>
     */
    public function positional(): array
    {
        $out = [];
        for ($position = 0; $this->knows($position) && isset($this->slots[$position]); $position++) {
            $out[] = $this->slots[$position];
        }

        return $out;
    }

    /**
     * Every slot, by position and by name. Only meaningful where {@see isOpaque()} is false: past an
     * unreadable spread this holds the arguments written before it and nothing about the rest.
     *
     * @return array<array-key, Node\Expr>
     */
    public function all(): array
    {
        return $this->slots;
    }
}
