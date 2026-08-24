<?php

declare(strict_types=1);

namespace Docuccino\Core\Draft;

/**
 * The paragraph a deprecation reason publishes in an operation's description. `deprecated: true` is
 * the machine-readable fact; the reason is the why, and the description is the only member OpenAPI
 * gives it. Both spellings — `#[DeprecatedOperation(reason:)]` and the text after a `@deprecated`
 * tag — come through here, so neither can word it differently from the other.
 */
final class DeprecationNote
{
    /**
     * Marked, because a reader meeting the paragraph on its own — a description dumped without the
     * deprecated flag beside it — has nothing else to tell them what it is about. The mark is also how
     * a format with no `deprecated` member of its own tells that the prose already says it.
     */
    private const string MARK = '**Deprecated:**';

    /** The note for a reason, or null where the reason says nothing. */
    public static function paragraph(?string $reason): ?string
    {
        $reason = trim($reason ?? '');

        return $reason === '' ? null : self::MARK.' '.$reason;
    }

    /** Whether prose already carries a note, so saying it again would only say it twice. */
    public static function marks(string $description): bool
    {
        return str_contains($description, self::MARK);
    }
}
