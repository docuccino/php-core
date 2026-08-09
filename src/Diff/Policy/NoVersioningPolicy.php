<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff\Policy;

use Docuccino\Core\Diff\Changeset;

/**
 * The "no versioning" policy: the contract is expected never to break. A breaking changeset is a
 * violation outright (no version bump can rescue it); a non-breaking changeset is always
 * satisfied. Versions are not inspected. Suits a single-consumer internal API that must stay
 * backwards-compatible forever.
 */
final class NoVersioningPolicy implements VersioningPolicy
{
    public function name(): string
    {
        return 'none';
    }

    public function evaluate(Changeset $changes, string $oldVersion, string $newVersion): PolicyVerdict
    {
        if (! $changes->isBreaking()) {
            return PolicyVerdict::ok($this->name());
        }

        return PolicyVerdict::violation(
            $this->name(),
            'breaking-forbidden',
            sprintf('%d breaking change(s) are not permitted under the "none" versioning policy.', count($changes->breakingChanges())),
        );
    }
}
