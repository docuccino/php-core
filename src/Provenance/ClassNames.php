<?php

declare(strict_types=1);

namespace Docuccino\Core\Provenance;

/**
 * A class name a published diagnostic may carry.
 *
 * A named class is already portable. An ANONYMOUS one is not: `::class` gives its base name, a NUL
 * byte, then `{absolute file}:{line}${n}` — so a transformer or an exception written inline would put
 * the build machine into every document that named it. Diagnostics are embedded in the document, so
 * that breaks byte-identical output where it is hardest to notice.
 *
 * The file is relativised through {@see SourcePathResolver}, the same ladder {@see MessagePaths} brings
 * a thrown message's paths to, because where the class stands is the only thing identifying it. The
 * `$n` goes: it counts the anonymous classes the PROCESS declared before this one, so two runs over the
 * same code need not agree on it.
 *
 * @internal
 */
final readonly class ClassNames
{
    public function __construct(private SourcePathResolver $paths) {}

    public function of(object $subject): string
    {
        $class = $subject::class;
        $declaredAt = strpos($class, "\0");

        if ($declaredAt === false) {
            return $class;
        }

        $where = substr($class, $declaredAt + 1);

        return substr($class, 0, $declaredAt)
            .' declared in '
            .$this->paths->relative(preg_replace('/\$[0-9a-f]+$/', '', $where) ?? $where);
    }
}
