<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff\Policy;

use Docuccino\Core\Diff\Changeset;
use Docuccino\Core\Support\PlainText;
use Docuccino\Core\Versioning\VersionOrder;

/**
 * Date-based versioning, Stripe-style: versions are `YYYY-MM-DD` and a breaking changeset needs a
 * strictly later one. Non-breaking changesets pass on any date, unchanged included — additive changes
 * ship without a version cut. An unparseable date on either side is a violation. A trailing suffix
 * like `-preview` is ignored, so `2026-08-01` and `2026-08-01-rc1` compare equal.
 *
 * Reading a date version is {@see VersionOrder}'s job, not this class's: the same two versions are
 * ordered again wherever an older document is derived, and one grammar read in two places is one that
 * eventually answers two things.
 */
final class DateVersionPolicy implements VersioningPolicy
{
    public function name(): string
    {
        return 'date';
    }

    public function evaluate(Changeset $changes, string $oldVersion, string $newVersion): PolicyVerdict
    {
        $delta = VersionOrder::date()->compare($oldVersion, $newVersion);

        if ($delta === null) {
            return PolicyVerdict::violation(
                $this->name(),
                'invalid-date-version',
                sprintf('Both versions must begin with a YYYY-MM-DD date (got "%s" → "%s").', PlainText::of($oldVersion), PlainText::of($newVersion)),
            );
        }

        if (! $changes->isBreaking()) {
            return PolicyVerdict::ok($this->name());
        }

        if ($delta < 0) {
            return PolicyVerdict::ok($this->name());
        }

        return PolicyVerdict::violation(
            $this->name(),
            'new-date-required',
            sprintf('Breaking changes require a newer date version than %s (got %s).', PlainText::of($oldVersion), PlainText::of($newVersion)),
        );
    }
}
