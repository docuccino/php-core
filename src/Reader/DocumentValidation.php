<?php

declare(strict_types=1);

namespace Docuccino\Core\Reader;

/**
 * What {@see ReadDocument::validate()} found. Valid means nothing was found — including that nothing
 * could not be checked, since a version with no schema to check it against is reported as a finding.
 *
 * Every finding is a place the document fails, so `$findings` can be counted, filtered by source and
 * shown as it is. Whether there were more than it holds is {@see isTruncated()}'s answer alone — no
 * line in the list says so, since how to say it is the caller's.
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
