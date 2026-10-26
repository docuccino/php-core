<?php

declare(strict_types=1);

namespace Docuccino\Core\Reader;

use Docuccino\Core\Contract\Pointer;

/**
 * The JSON pointers a scan of a document's text is looking for, as a tree of their segments, and the offset
 * each was found at. A scan walks the text once and descends only where a sought pointer leads, so what it
 * costs is the text, never the number of pointers.
 *
 * The root is node 0, and `/` names it as well as `''`: every check in this package reports the root as `/`.
 *
 * @internal
 */
final class PointerTrie
{
    /** @var array<int, array<array-key, int>> each node's children, by segment */
    private array $children = [[]];

    /** @var array<int, list<string>> the pointers each node is */
    private array $pointers = [[]];

    /** @var array<string, int> */
    private array $offsets = [];

    private int $sought = 0;

    /**
     * @param  list<string>  $pointers
     */
    public function __construct(array $pointers)
    {
        foreach (array_unique($pointers) as $pointer) {
            $segments = self::segments($pointer);
            if ($segments === null) {
                continue;
            }

            $node = 0;
            foreach ($segments as $segment) {
                $child = $this->children[$node][$segment] ?? null;
                if ($child === null) {
                    $child = count($this->children);
                    $this->children[$node][$segment] = $child;
                    $this->children[$child] = [];
                    $this->pointers[$child] = [];
                }

                $node = $child;
            }

            $this->pointers[$node][] = $pointer;
            $this->sought++;
        }
    }

    /** The node $segment leads to from $node, or null where no sought pointer goes that way. */
    public function child(int $node, int|string $segment): ?int
    {
        return $this->children[$node][$segment] ?? null;
    }

    /**
     * @return array<array-key, int>
     */
    public function children(int $node): array
    {
        return $this->children[$node];
    }

    public function leadsFurther(int $node): bool
    {
        return $this->children[$node] !== [];
    }

    /** $node's pointers are written at $offset. */
    public function record(int $node, int $offset): void
    {
        foreach ($this->pointers[$node] as $pointer) {
            $this->offsets[$pointer] = $offset;
        }
    }

    /** Every pointer below $node is written at $offset, as the members of a value an alias names are. */
    public function recordBelow(int $node, int $offset): void
    {
        foreach ($this->children[$node] as $child) {
            $this->record($child, $offset);
            $this->recordBelow($child, $offset);
        }
    }

    /** What was recorded for $node and below, forgotten: a JSON member written twice is read as written last. */
    public function forget(int $node): void
    {
        foreach ($this->pointers[$node] as $pointer) {
            unset($this->offsets[$pointer]);
        }

        foreach ($this->children[$node] as $child) {
            $this->forget($child);
        }
    }

    /** Whether every sought pointer has been found. */
    public function complete(): bool
    {
        return count($this->offsets) === $this->sought;
    }

    public function offset(string $pointer): ?int
    {
        return $this->offsets[$pointer] ?? null;
    }

    /**
     * $pointer's segments, unescaped, or null where it is not a pointer at all.
     *
     * @return list<string>|null
     */
    private static function segments(string $pointer): ?array
    {
        if ($pointer === '' || $pointer === '/') {
            return [];
        }

        if (! str_starts_with($pointer, '/')) {
            return null;
        }

        return array_map(Pointer::unescape(...), explode('/', substr($pointer, 1)));
    }
}
