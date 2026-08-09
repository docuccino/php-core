<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff\Policy;

/**
 * Resolves the built-in {@see VersioningPolicy} for a per-document config keyword
 * (`versioning: semver|date|none`). Unknown keywords fall back to {@see NoVersioningPolicy} — the
 * strictest built-in — so a typo fails closed rather than silently permitting breaking changes.
 */
final class VersioningPolicies
{
    public static function for(string $keyword): VersioningPolicy
    {
        return match ($keyword) {
            'semver' => new SemverPolicy,
            'date' => new DateVersionPolicy,
            default => new NoVersioningPolicy,
        };
    }
}
