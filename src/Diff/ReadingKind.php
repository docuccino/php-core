<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff;

/**
 * How the diff reads a keyword that carries no subschema and refines no value — the axis of
 * {@see SchemaReading}'s decision. An enum rather than string constants because a kind is minted here
 * and never read off a document: every `match` over it is exhaustive with no default, so a kind added
 * with no reading of its own is a PHPStan failure at the comparison that owes it one instead of a
 * guess made at runtime.
 *
 * @internal
 */
enum ReadingKind: string
{
    /** `discriminator` — which subschema a payload deserialises as, and so which type a client builds. */
    case Discriminator = 'discriminator';

    /** `nullable` — OAS 3.0's spelling of the `null` branch of a type union. */
    case Nullability = 'nullability';

    /** `$anchor` — a name a `$ref` may resolve, and this comparison resolves none. */
    case Identity = 'identity';

    /** `$id` — that, AND the base URI every `$ref` beneath it resolves against. */
    case Base = 'base';

    /** `$schema` — the dialect every keyword beside it is read in. */
    case Dialect = 'dialect';

    /** Read by a comparison of its own, named in the row beside it, so this one reports nothing. */
    case Elsewhere = 'elsewhere';

    /** Read as an annotation: reported by the annotation comparison, breaking under no policy. */
    case Annotation = 'annotation';

    /** Read by nothing, deliberately, for the reason recorded beside it. */
    case Unread = 'unread';

    /** A keyword the draft model knows and nobody has decided. Reported, and classed breaking. */
    case Undecided = 'undecided';
}
