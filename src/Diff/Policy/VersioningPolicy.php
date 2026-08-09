<?php

declare(strict_types=1);

namespace Docuccino\Core\Diff\Policy;

use Docuccino\Core\Diff\Changeset;

/**
 * Enforces a versioning discipline against a semantic {@see Changeset} (design §6): given the
 * changeset and both documents' `info.version`, it returns a {@see PolicyVerdict} saying whether
 * the version delta is adequate for the change severity. Wired into `docuccino:diff --enforce`
 * (nonzero exit for CI). Longitudinal governance — deprecation windows, multi-release history,
 * cross-repo policy — is deliberately SaaS territory, not modelled here.
 */
interface VersioningPolicy
{
    /** The stable policy identifier, e.g. `semver`. */
    public function name(): string;

    public function evaluate(Changeset $changes, string $oldVersion, string $newVersion): PolicyVerdict;
}
