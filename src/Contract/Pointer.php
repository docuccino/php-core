<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract;

use Docuccino\Core\Support\Arr;

/**
 * RFC 6901 pointer arithmetic — the one place `~` and `/` get escaped, so a path key like
 * `/api/invoices/{invoice}` survives being spelled as a pointer segment.
 *
 * @internal
 */
final class Pointer
{
    /** @param list<string|int> $segments */
    public static function of(array $segments): string
    {
        $pointer = '';
        foreach ($segments as $segment) {
            $pointer .= '/'.self::escape((string) $segment);
        }

        return $pointer;
    }

    public static function append(string $pointer, string|int $segment): string
    {
        return $pointer.'/'.self::escape((string) $segment);
    }

    public static function escape(string $segment): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $segment);
    }

    /** The inverse. `~1` before `~0`, or an escaped `~1` would come back as a `/`. */
    public static function unescape(string $segment): string
    {
        return str_replace(['~1', '~0'], ['/', '~'], $segment);
    }

    /**
     * The same read over the object graph, which is where a value has to come from when it is going to
     * be schema-checked: `{}` and `[]` are different instances and only the object form tells them apart.
     *
     * @param  list<string>  $segments
     */
    public static function readGraph(object $graph, array $segments): mixed
    {
        $node = $graph;

        foreach ($segments as $segment) {
            if (is_object($node) && property_exists($node, $segment)) {
                $node = $node->{$segment};

                continue;
            }

            if (is_array($node) && array_key_exists($segment, $node)) {
                $node = $node[$segment];

                continue;
            }

            return null;
        }

        return $node;
    }

    /**
     * The value the pointer addresses, or null when any step is missing. Only reads arrays — the whole
     * document is decoded associatively.
     *
     * @param  array<array-key, mixed>  $document
     * @param  list<string>  $segments  unescaped path segments
     */
    public static function read(array $document, array $segments): mixed
    {
        return Arr::valueAt($document, $segments);
    }
}
