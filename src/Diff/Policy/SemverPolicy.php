<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff\Policy;

use Docuccino\Core\Diff\Changeset;
use Docuccino\Core\Support\PlainText;

/**
 * Semantic-versioning policy: a breaking changeset needs a major bump — or, at `0.y.z` where the API
 * is still unstable, at least a minor one. A non-breaking changeset always passes; recommending a
 * minor/patch bump is advice, not a gate. An unparseable version on either side is a violation, so CI
 * never green-lights a malformed bump.
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
                sprintf('Both versions must be semver (got "%s" → "%s").', PlainText::of($oldVersion), PlainText::of($newVersion)),
            );
        }

        if (! $changes->isBreaking()) {
            return PolicyVerdict::ok($this->name());
        }

        [$oldMajor, $oldMinor] = $old;
        [$newMajor, $newMinor] = $new;

        // At 0.y.z a breaking change is signalled by a minor bump; from 1.0.0 it needs a major.
        if ($oldMajor === 0) {
            if ($newMajor > 0 || $newMinor > $oldMinor) {
                return PolicyVerdict::ok($this->name());
            }

            return PolicyVerdict::violation(
                $this->name(),
                'minor-bump-required',
                sprintf('Breaking changes on a 0.x version require at least a minor bump (%s → %s).', PlainText::of($oldVersion), PlainText::of($newVersion)),
                sprintf('0.%d.0', $oldMinor + 1),
            );
        }

        if ($newMajor > $oldMajor) {
            return PolicyVerdict::ok($this->name());
        }

        return PolicyVerdict::violation(
            $this->name(),
            'major-bump-required',
            sprintf('Breaking changes require a major bump (%s → %s).', PlainText::of($oldVersion), PlainText::of($newVersion)),
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
