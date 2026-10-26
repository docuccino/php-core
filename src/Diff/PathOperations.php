<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff;

use Docuccino\Core\Contract\Pointer;
use Docuccino\Core\Document\Operation;
use Docuccino\Core\Document\PathItem;
use Docuccino\Core\Support\Arr;

/**
 * Every operation a path item states — its fixed methods, then OpenAPI 3.2's `additionalOperations` — read
 * here by every walk the differ makes over operations and by the node index, so none of them sees fewer than
 * the comparison does, and the differ's keys and the index's spell an operation alike.
 *
 * A custom method is sent as its key is written, case and all: a custom `get` is another method than the
 * fixed `get` (`GET`), and a `GET` key, which OpenAPI forbids, sends the fixed one's request line from
 * somewhere else. So each is told apart by where it is WRITTEN — the fixed method's name uppercased, or
 * `additionalOperations/` and the key escaped as a pointer escapes it — which no two share. A key spells
 * that before a space and the path after it, so a space in a custom method, which only a document OpenAPI
 * calls invalid can hold, is escaped as `~2`: kept, it could spell the start of another operation's path.
 *
 * @internal
 */
final class PathOperations
{
    private const string CUSTOM = 'additionalOperations';

    /**
     * @return list<array{0: string, 1: string, 2: Operation}> the method a client sends, where the operation is written, and the operation
     */
    public static function of(PathItem $item): array
    {
        $out = [];

        foreach ($item->operations as $method => $operation) {
            $out[] = [strtoupper($method), strtoupper($method), $operation];
        }

        foreach (self::customIn($item->rest) as [$method, $operation]) {
            $out[] = [$method, self::custom($method), Operation::fromArray(Arr::stringKeyed($operation))];
        }

        return $out;
    }

    /**
     * The same operations, read off $item as written.
     *
     * @param  array<array-key, mixed>  $item
     * @return list<array{0: string, 1: string, 2: array<array-key, mixed>}> where the operation is written, the pointer to it below the item, and the operation
     */
    public static function written(array $item): array
    {
        $out = [];

        foreach (PathItem::METHODS as $method) {
            if (is_array($item[$method] ?? null)) {
                $out[] = [strtoupper($method), '/'.$method, $item[$method]];
            }
        }

        foreach (self::customIn($item) as [$method, $operation]) {
            $out[] = [self::custom($method), '/'.self::CUSTOM.'/'.Pointer::escape($method), $operation];
        }

        return $out;
    }

    /**
     * $item as written, with $change applied to every operation it states, fixed or custom.
     *
     * @param  array<array-key, mixed>  $item
     * @param  callable(array<array-key, mixed>): array<array-key, mixed>  $change
     * @return array<array-key, mixed>
     */
    public static function rewritten(array $item, callable $change): array
    {
        foreach (PathItem::METHODS as $method) {
            if (is_array($item[$method] ?? null)) {
                $item[$method] = $change($item[$method]);
            }
        }

        $custom = self::customIn($item);
        if ($custom !== []) {
            /** @var array<array-key, mixed> $written */
            $written = $item[self::CUSTOM];
            foreach ($custom as [$method, $operation]) {
                $written[$method] = $change($operation);
            }
            $item[self::CUSTOM] = $written;
        }

        return $item;
    }

    /** Where the custom operation $method is written, as a key spells it. */
    private static function custom(string $method): string
    {
        return self::CUSTOM.'/'.str_replace(' ', '~2', Pointer::escape($method));
    }

    /**
     * The custom operations among $members, each with its method — a pair rather than a key, since PHP
     * hands a method spelt in digits back as an int. A member that is not an object states no operation,
     * as a fixed method's would not.
     *
     * @param  array<array-key, mixed>  $members
     * @return list<array{string, array<array-key, mixed>}>
     */
    private static function customIn(array $members): array
    {
        $custom = $members[self::CUSTOM] ?? null;

        $out = [];
        foreach (is_array($custom) ? $custom : [] as $method => $operation) {
            if (is_array($operation)) {
                $out[] = [(string) $method, $operation];
            }
        }

        return $out;
    }
}
