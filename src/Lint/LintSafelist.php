<?php

declare(strict_types=1);

namespace Docuccino\Core\Lint;

/**
 * The one reading of an `allow` entry, shared by everything that consults one — every lint, and the
 * recorder's redaction. A pointer reaches a safelist spelled either bare (`/components/…`, the form a
 * message prints) or as the URI fragment a `$ref` uses (`#/components/…`), so the leading `#` comes
 * off both the entry and the subject and all four combinations land.
 *
 * An entry matches EXACTLY what it spells, and deliberately so: both callers are controls rather than
 * conveniences — one silences a leakage finding, the other un-redacts a recorded value that credential
 * matching flagged — and a control that quietly accepted a `*` would widen what an existing config
 * entry silences without anybody having written a wildcard. {@see Docuccino\Core\Support\Glob} is the
 * product's wildcard grammar, for the readers that document one.
 *
 * @internal
 */
final class LintSafelist
{
    /**
     * Whether any of the names a subject goes by is named by one of the entries.
     *
     * @param  list<string>  $allow
     */
    public static function matches(array $allow, ?string ...$subjects): bool
    {
        $entries = array_map(self::canonical(...), $allow);

        foreach ($subjects as $subject) {
            if ($subject !== null && in_array(self::canonical($subject), $entries, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The fragment marker off a pointer. Only `#/` counts, so a subject that is a name rather than a
     * pointer — a tag, an operationId, a property — is never rewritten by a `#` it happens to start with.
     */
    private static function canonical(string $subject): string
    {
        return str_starts_with($subject, '#/') ? substr($subject, 1) : $subject;
    }
}
