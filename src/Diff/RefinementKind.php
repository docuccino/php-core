<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff;

/**
 * How two values of one refinement keyword are compared — the axis of {@see SchemaRefinement}'s
 * decision that settles which way an edit moves the schema carrying it. An enum rather than string
 * constants because a kind is minted here and never read off a document: every `match` over it is
 * exhaustive with no default, so a kind added with no reading of its own is a PHPStan failure at the
 * comparison that owes it one instead of a guess made at runtime.
 *
 * @internal
 */
enum RefinementKind: string
{
    /** A ceiling — `maxLength`, `maximum`, `maxItems`. Lower is narrower, and no ceiling is no bound. */
    case UpperBound = 'upperBound';

    /** A floor — `minLength`, `minimum`, `minItems`. Higher is narrower. */
    case LowerBound = 'lowerBound';

    /** `multipleOf`, where narrower is "a multiple of what it was" rather than larger or smaller. */
    case Divisor = 'divisor';

    /** `uniqueItems`: off to on narrows, on to off widens, and absent is off. */
    case Flag = 'flag';

    /** `pattern`, `const` and the two content keywords — two values that compare only for equality. */
    case Opaque = 'opaque';

    /** Read by a comparison of its own, named in the rule beside it, so this one reports nothing. */
    case Elsewhere = 'elsewhere';

    /** A refinement the draft model knows and nobody has decided. Reported, and classed breaking. */
    case Undecided = 'undecided';
}
