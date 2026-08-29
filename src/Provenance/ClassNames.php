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

    /**
     * {@see ofName()} for a caller with no application root to strip — a schema mapper, which is handed
     * a `class-string` and nothing about where the application lives. The resolver still relativises
     * against the nearest `composer.json` ancestor, so an inline class in an app or a package reads as
     * where it stands either way.
     */
    public static function publishable(string $class): string
    {
        return (new self(new RootRelativeSourcePathResolver('')))->ofName($class);
    }

    public function of(object $subject): string
    {
        return $this->ofName($subject::class);
    }

    /** The same for a name already in hand — a `class-string` a mapper was handed rather than an instance. */
    public function ofName(string $class): string
    {
        return $this->inText($class);
    }

    /**
     * The same rewrite wherever the marker turns up EMBEDDED in a sentence — a `TypeError` naming the
     * anonymous class it was given, say. {@see MessagePaths} comes here first for that reason: the path
     * inside the marker is one it would relativise anyway, and the NUL byte and the counter beside it
     * are not something a path grammar can reach.
     */
    public function inText(string $text): string
    {
        // Bounded first and refused rather than handed back: `.+?` backtracks, and a pass that gives
        // up must not publish the absolute file inside the marker. {@see PublishableText} owns both.
        $bounded = PublishableText::bounded($text);

        return PublishableText::orRefused(preg_replace_callback(
            '/\0(?<file>.+?):(?<line>\d+)\$[0-9a-f]+/',
            fn (array $match): string => ' declared in '.$this->paths->relative($match['file']).':'.$match['line'],
            $bounded,
        ));
    }
}
