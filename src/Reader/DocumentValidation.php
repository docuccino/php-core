<?php

declare(strict_types=1);

namespace Docuccino\Core\Reader;

/**
 * What {@see ReadDocument::validate()} found. Valid means nothing was found — including that nothing
 * could not be checked, since a version with no schema to check it against is reported as a finding.
 *
 * A list cut at the caller's limit ends in one more finding saying so, and {@see isTruncated()} answers
 * the same question for code.
 */
final readonly class DocumentValidation
{
    /**
     * @param  list<DocumentFinding>  $findings
     */
    public function __construct(
        public array $findings,
        private bool $truncated = false,
    ) {}

    public function isValid(): bool
    {
        return $this->findings === [];
    }

    public function isTruncated(): bool
    {
        return $this->truncated;
    }

    /**
     * @return list<DocumentFinding>
     */
    public function findingsFrom(FindingSource $source): array
    {
        return array_values(array_filter($this->findings, static fn (DocumentFinding $finding): bool => $finding->source === $source));
    }
}
