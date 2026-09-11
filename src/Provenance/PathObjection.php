<?php

declare(strict_types=1);

namespace Docuccino\Core\Provenance;

/**
 * Every reason {@see MessagePaths} has for NOT rewriting a run, with the claim it denies and how far
 * it goes. An objection is evidence rather than a veto: a suggestive one is answered by a conclusive
 * reason covering the text a rewrite would remove, and only a conclusive one settles the question by
 * itself.
 *
 * The rule the two halves compose by is stated once, in {@see MessagePaths}. Adding a case here is a
 * build error until all three matches below and {@see MessagePaths::trips()} decide it.
 *
 * @internal
 */
enum PathObjection
{
    /** A wrapper's tail is itself a URL, so the run names a host — `compress.zlib://http://…`. */
    case NestedScheme;

    /**
     * No root that accounts for the text is more than one segment. `/app` is a container's checkout
     * and equally a prefix an application mounts routes under, so it is no evidence the text is a
     * machine word — and it is measured over every root that accounts for it, because the depth that
     * matters is the depth of whichever one would be stripped.
     */
    case ShallowRoot;

    /** A brace: a URI template, `/api/users/{user}` — or a shell glob, which is why it is not decisive. */
    case Brace;

    /** A backslash inside a POSIX run: a regex, a JSON string — or a class name against the path. */
    case Backslash;

    /** What it denies. */
    public function opposes(): PathClaim
    {
        return match ($this) {
            self::NestedScheme, self::Brace, self::Backslash => PathClaim::RunIsAPath,
            self::ShallowRoot => PathClaim::PrefixIsAMachineWord,
        };
    }

    /**
     * Whether it settles the question alone. A nested scheme and a shallow root do: nothing but a
     * stream address is spelled `scheme://scheme://`, and no depth of evidence makes a one-segment
     * root a word only a machine would write. A brace and a backslash are strong evidence of a
     * template and of an escape, and weak evidence against a path.
     */
    public function isConclusive(): bool
    {
        return match ($this) {
            self::NestedScheme, self::ShallowRoot => true,
            self::Brace, self::Backslash => false,
        };
    }

    /**
     * The characters it is spelled with, which is where the text a prefix strip may remove ends. Empty
     * for a conclusive objection: nothing answers one, so there is no anchor to measure.
     */
    public function characters(): string
    {
        return match ($this) {
            self::NestedScheme, self::ShallowRoot => '',
            self::Brace => '{}',
            self::Backslash => '\\',
        };
    }
}
