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
    /**
     * The last namespace segment of an FQCN (or the input, if unqualified).
     *
     * An ANONYMOUS class ends at its `@anonymous` marker. `::class` continues past it with a NUL byte,
     * the ABSOLUTE file it was written in and a counter of the anonymous classes the PROCESS declared
     * first — and none of that is a namespace separator, so shortening alone would carry the whole tail
     * into a component name, a tag or an operationId. What is left names the thing rather than the
     * machine; two anonymous classes that collide on it are separated the way any other pair is, by the
     * identity ladder `ComponentNames` climbs. A label that WANTS the location says so by going through
     * `Provenance\ClassNames` instead, which relativises it.
     */
    public static function short(string $fqcn): string
    {
        $marker = strpos($fqcn, "\0");
        if ($marker !== false) {
            $fqcn = substr($fqcn, 0, $marker);
        }

        $pos = strrpos($fqcn, '\\');

        return $pos !== false ? substr($fqcn, $pos + 1) : $fqcn;
    }
}
