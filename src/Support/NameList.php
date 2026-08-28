<?php

declare(strict_types=1);

namespace Docuccino\Core\Support;

/**
 * The list of names a diagnostic sentence ends with — what the operation documents, what the schema
 * publishes, what the route template has — capped and made safe to print.
 *
 * One owner because the two halves are policy rather than phrasing. The CAP is where a list stops being
 * read and starts being scrolled, and a report that names eight alternatives and one that names two
 * hundred are not the same report. The ESCAPING is not optional: every name here is recovered from an
 * application's own code — a validation rule key, a model attribute, a query string it composes — so it
 * reaches a terminal unread, and an escape sequence in one recolours the line it is printed on
 * ({@see PlainText}).
 *
 * Only the list is shared. The sentence around it is the caller's, because each names a different kind
 * of thing, and a sentence that fits all of them would be true of none.
 *
 * This is a sentence-embedded list. A CLI's own line-per-entry lists cap themselves for a different
 * reason and read nothing like this, so they are deliberately not routed here.
 *
 * @internal
 */
final class NameList
{
    /** Past this a reader stops checking their spelling against the list and starts scrolling past it. */
    public const int MAX = 8;

    /**
     * `a, b and 3 more`, or null when there is nothing to list — the caller says what an empty set
     * means, since "it documents none" and "there are none configured" are different facts.
     *
     * @param  list<string>  $names
     */
    public static function of(array $names): ?string
    {
        if ($names === []) {
            return null;
        }

        $shown = array_slice($names, 0, self::MAX);
        $extra = count($names) - count($shown);

        return implode(', ', array_map(PlainText::of(...), $shown))
            .($extra > 0 ? sprintf(' and %d more', $extra) : '');
    }
}
