<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff\Policy;

use Docuccino\Core\Diff\Changeset;

/**
 * Semantic-versioning policy: a breaking changeset requires a major bump (per semver §4, a `0.y.z`
 * document being still-unstable, a breaking change there requires at least a minor bump). A
 * non-breaking changeset is always satisfied — recommending a minor/patch bump is advisory, not a
 * gate. An unparseable version on either side is a violation, so CI never green-lights a malformed
 * bump.
 */
final class SemverPolicy implements VersioningPolicy
{
    public function name(): string
    {
        return 'semver';
    }

    public function evaluate(Changeset $changes, string $oldVersion, string $newVersion): PolicyVerdict
    {
        $old = self::parse($oldVersion);
        $new = self::parse($newVersion);

        if ($old === null || $new === null) {
            return PolicyVerdict::violation(
                $this->name(),
                'invalid-version',
                sprintf('Both versions must be semver (got "%s" → "%s").', $oldVersion, $newVersion),
            );
        }

        if (! $changes->isBreaking()) {
            return PolicyVerdict::ok($this->name());
        }

        [$oldMajor, $oldMinor] = $old;
        [$newMajor, $newMinor] = $new;

        // Semver §4: while at 0.y.z the public API is unstable, so a breaking change is signalled by
        // a minor bump; from 1.0.0 onwards a breaking change requires a major bump.
        if ($oldMajor === 0) {
            if ($newMajor > 0 || $newMinor > $oldMinor) {
                return PolicyVerdict::ok($this->name());
            }

            return PolicyVerdict::violation(
                $this->name(),
                'minor-bump-required',
                sprintf('Breaking changes on a 0.x version require at least a minor bump (%s → %s).', $oldVersion, $newVersion),
                sprintf('0.%d.0', $oldMinor + 1),
            );
        }

        if ($newMajor > $oldMajor) {
            return PolicyVerdict::ok($this->name());
        }

        return PolicyVerdict::violation(
            $this->name(),
            'major-bump-required',
            sprintf('Breaking changes require a major bump (%s → %s).', $oldVersion, $newVersion),
            sprintf('%d.0.0', $oldMajor + 1),
        );
    }

    /**
     * @return array{0: int, 1: int, 2: int}|null
     */
    private static function parse(string $version): ?array
    {
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)(?:[-+].*)?$/', trim($version), $m) !== 1) {
            return null;
        }

        return [(int) $m[1], (int) $m[2], (int) $m[3]];
    }
}
