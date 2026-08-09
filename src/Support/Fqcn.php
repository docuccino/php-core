<?php

declare(strict_types=1);

namespace Docuccino\Core\Support;

/**
 * The one "short class name" helper. Throw-frame labels, the engine's self label, constant rendering,
 * component naming and operationId derivation all short an FQCN for display and must do it
 * identically, so they all come here rather than keeping private copies that drift.
 *
 * Public, not `@internal` — built-in integrations use it directly instead of inlining a copy to dodge
 * the arch-test allow-list.
 */
final class Fqcn
{
    /** The last namespace segment of an FQCN (or the input, if unqualified). */
    public static function short(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos !== false ? substr($fqcn, $pos + 1) : $fqcn;
    }
}
