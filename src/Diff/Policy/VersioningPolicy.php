<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff\Policy;

use Docuccino\Core\Diff\Changeset;

/**
 * Enforces a versioning discipline against a semantic {@see Changeset}: given the changeset and both
 * documents' `info.version`, returns a {@see PolicyVerdict} on whether the version delta matches the
 * change severity. `docuccino:diff --enforce` exits nonzero on a violation. Longitudinal governance —
 * deprecation windows, multi-release history, cross-repo policy — isn't modelled here.
 */
interface VersioningPolicy
{
    /** The stable policy identifier, e.g. `semver`. */
    public function name(): string;

    public function evaluate(Changeset $changes, string $oldVersion, string $newVersion): PolicyVerdict;
}
