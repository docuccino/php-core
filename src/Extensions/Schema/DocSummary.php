<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Schema;

use Docuccino\Core\TypeGrammar\DocBlockReader;

/**
 * A docblock's first prose paragraph, on one line — the shape a per-VALUE description wants, where the
 * OAS summary/description split wants the whole prose. A thin front for {@see DocBlockReader}, so one
 * grammar (and one inline-tag rule) stands behind every description core publishes.
 */
final class DocSummary
{
    /** Stateless, so one reader serves every call — a description is asked for per enum case. */
    private static ?DocBlockReader $reader = null;

    /** Null when the docblock is absent (reflection's `false`) or carries no prose. */
    public static function of(string|false $doc): ?string
    {
        if ($doc === false) {
            return null;
        }

        self::$reader ??= new DocBlockReader;
        $prose = self::$reader->summary($doc);
        if ($prose === null) {
            return null;
        }

        $parts = preg_split('/\R{2,}/', $prose, 2);
        $summary = trim((string) preg_replace('/\s+/', ' ', $parts[0] ?? $prose));

        return $summary === '' ? null : $summary;
    }
}
