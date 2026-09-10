<?php

declare(strict_types=1);

namespace Docuccino\Core\Provenance;

/**
 * Every reason {@see MessagePaths} has for rewriting a run, with the claim it proves and how far it
 * goes. A reason says WHAT it establishes rather than that it establishes something: a conclusive one
 * settles its claim on its own, a suggestive one is worth nothing while anything objects.
 *
 * The rule the two halves compose by is stated once, in {@see MessagePaths}. Adding a case here is a
 * build error until both matches below and {@see MessagePaths::stands()} decide it.
 *
 * @internal
 */
enum PathReason
{
    /** A stream wrapper whose tail can be nothing but a file on this machine (`phar://`, `glob://`). */
    case LocalWrapper;

    /** A Windows drive or a UNC share: shapes no route signature, template or JSON pointer has. */
    case WindowsRoot;

    /** The ladder recognised a root in front of this text — the base path, or a `composer.json` ancestor. */
    case RecognisedRoot;

    /** The last segment names a file (`Reader.php`), which is all shape has left to go on. */
    case FileShape;

    /** What it establishes. Only one reason speaks about a prefix; the rest speak about the run. */
    public function proves(): PathClaim
    {
        return match ($this) {
            self::LocalWrapper, self::WindowsRoot, self::FileShape => PathClaim::RunIsAPath,
            self::RecognisedRoot => PathClaim::PrefixIsAMachineWord,
        };
    }

    /**
     * Whether it settles its claim alone. Shape does not: a route signature with a format suffix and a
     * path template naming a file are spelled exactly as a file is, so it may only speak where nothing
     * objects.
     */
    public function isConclusive(): bool
    {
        return match ($this) {
            self::LocalWrapper, self::WindowsRoot, self::RecognisedRoot => true,
            self::FileShape => false,
        };
    }
}
