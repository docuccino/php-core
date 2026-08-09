<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Ordering;

use ReflectionClass;

/**
 * Deterministic topological sort of extensions by their {@see ExtensionOrder}. `before`/`after` become
 * hard edges; among nodes with no remaining prerequisite it picks by priority descending, then FQCN
 * ascending, then original index — so output bytes never depend on registration order. A cycle raises
 * {@see CyclicExtensionOrderException}.
 */
final class ExtensionSorter
{
    /**
     * @template T of object
     *
     * @param  list<T>  $extensions
     * @return list<T>
     */
    public function sort(array $extensions): array
    {
        $nodes = [];
        foreach ($extensions as $index => $extension) {
            $order = $this->orderOf($extension);
            $nodes[$index] = [
                'extension' => $extension,
                'class' => $extension::class,
                'priority' => $order->priority,
                'before' => $order->before,
                'after' => $order->after,
            ];
        }

        /** @var array<string, list<int>> $byClass */
        $byClass = [];
        foreach ($nodes as $index => $node) {
            $byClass[$node['class']][] = $index;
        }

        /** @var array<int, list<int>> $successors */
        $successors = array_fill_keys(array_keys($nodes), []);
        $inDegree = array_fill_keys(array_keys($nodes), 0);

        $addEdge = static function (int $from, int $to) use (&$successors, &$inDegree): void {
            if ($from === $to) {
                return;
            }
            $successors[$from][] = $to;
            $inDegree[$to]++;
        };

        foreach ($nodes as $index => $node) {
            foreach ($node['before'] as $target) {
                foreach ($byClass[$target] ?? [] as $to) {
                    $addEdge($index, $to);
                }
            }
            foreach ($node['after'] as $target) {
                foreach ($byClass[$target] ?? [] as $from) {
                    $addEdge($from, $index);
                }
            }
        }

        $sorted = [];
        $remaining = array_keys($nodes);

        while ($remaining !== []) {
            $ready = array_values(array_filter($remaining, static fn (int $i): bool => $inDegree[$i] === 0));

            if ($ready === []) {
                throw new CyclicExtensionOrderException(array_map(
                    static fn (int $i): string => $nodes[$i]['class'],
                    $remaining,
                ));
            }

            usort($ready, static function (int $a, int $b) use ($nodes): int {
                return $nodes[$b]['priority'] <=> $nodes[$a]['priority']
                    ?: strcmp($nodes[$a]['class'], $nodes[$b]['class'])
                    ?: $a <=> $b;
            });

            $pick = $ready[0];
            $sorted[] = $nodes[$pick]['extension'];

            foreach ($successors[$pick] as $to) {
                $inDegree[$to]--;
            }

            $remaining = array_values(array_filter($remaining, static fn (int $i): bool => $i !== $pick));
        }

        return $sorted;
    }

    private function orderOf(object $extension): ExtensionOrder
    {
        $reflection = new ReflectionClass($extension);
        $attributes = $reflection->getAttributes(ExtensionOrder::class);

        if ($attributes === []) {
            return new ExtensionOrder;
        }

        return $attributes[0]->newInstance();
    }
}
