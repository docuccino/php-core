<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff;

/**
 * Which way one keyword moved between two schemas — a refinement's own value space
 * ({@see SchemaRefinement}), and the members {@see SchemaReading} compares beside it, since the
 * direction vocabulary is the same one and the verdict it earns is too. `Incomparable` is a finding
 * rather than a shrug: two `pattern`s, two dialects of `exclusiveMinimum`, a `multipleOf` neither side
 * divides, a discriminator mapping entry repointed — the change is real and its direction is what
 * cannot be computed, so it is reported and classed breaking.
 *
 * @internal
 */
enum RefinementMove: string
{
    /** Nothing moved — including a keyword written out at the value its absence already meant. */
    case Unchanged = 'unchanged';

    /** Fewer values satisfy the schema than did. */
    case Narrowed = 'narrowed';

    /** More values satisfy the schema than did. */
    case Widened = 'widened';

    /** The two values are not ordered by anything this comparison can read. */
    case Incomparable = 'incomparable';

    /**
     * The suffix a direction's code takes — `schema.refinement-widened`, `schema.nullable-changed`. It is
     * the case name for three of the four; a direction nothing can order publishes as `changed`, because
     * "incomparable" describes the comparison and a code describes the edit. `unchanged` never reaches a
     * document: every caller returns before publishing one.
     */
    public function suffix(): string
    {
        return $this === self::Incomparable ? 'changed' : $this->value;
    }
}
