<?php

declare(strict_types=1);

namespace Docuccino\Core\Lint;

/**
 * The switch and safelist the document-completeness lints share: an off-switch, and a list of
 * subjects to skip. A safelist entry is always a string the rule already put in its message — an
 * operation signature, an operationId, a tag name — so silencing a finding never needs a second
 * vocabulary.
 */
final readonly class LintRuleOptions
{
    /**
     * @param  list<string>  $allow  subjects to skip, spelled as the rule's message spells them
     */
    public function __construct(
        public bool $enabled = true,
        public array $allow = [],
    ) {}

    /** Whether any of the names this finding goes by is safelisted. */
    public function silences(?string ...$subjects): bool
    {
        foreach ($subjects as $subject) {
            if ($subject !== null && in_array($subject, $this->allow, true)) {
                return true;
            }
        }

        return false;
    }
}
