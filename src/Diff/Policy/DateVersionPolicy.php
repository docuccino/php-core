<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff\Policy;

use Docuccino\Core\Diff\Changeset;
use Docuccino\Core\Support\PlainText;

/**
 * Date-based versioning, Stripe-style: versions are `YYYY-MM-DD` and a breaking changeset needs a
 * strictly later one. Non-breaking changesets pass on any date, unchanged included — additive changes
 * ship without a version cut. An unparseable date on either side is a violation. A trailing suffix
 * like `-preview` is ignored, so `2026-08-01` and `2026-08-01-rc1` compare equal.
 */
final class DateVersionPolicy implements VersioningPolicy
{
    public function name(): string
    {
        return 'date';
    }

    public function evaluate(Changeset $changes, string $oldVersion, string $newVersion): PolicyVerdict
    {
        $old = self::parse($oldVersion);
        $new = self::parse($newVersion);

        if ($old === null || $new === null) {
            return PolicyVerdict::violation(
                $this->name(),
                'invalid-date-version',
                sprintf('Both versions must begin with a YYYY-MM-DD date (got "%s" → "%s").', PlainText::of($oldVersion), PlainText::of($newVersion)),
            );
        }

        if (! $changes->isBreaking()) {
            return PolicyVerdict::ok($this->name());
        }

        if ($new > $old) {
            return PolicyVerdict::ok($this->name());
        }

        return PolicyVerdict::violation(
            $this->name(),
            'new-date-required',
            sprintf('Breaking changes require a newer date version than %s (got %s).', PlainText::of($oldVersion), PlainText::of($newVersion)),
        );
    }

    /** The comparable `YYYY-MM-DD` prefix, or null when the string does not start with one. */
    private static function parse(string $version): ?string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', trim($version), $m) === 1
            ? $m[1].'-'.$m[2].'-'.$m[3]
            : null;
    }
}
