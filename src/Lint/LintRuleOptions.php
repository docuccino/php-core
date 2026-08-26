<?php

declare(strict_types=1);

namespace Docuccino\Core\Lint;

/**
 * The switch and safelist the document-completeness lints share: an off-switch, and a list of
 * subjects to skip. A safelist entry is always a string the rule already put in its message — an
 * operation signature, an operationId, a tag name, a JSON pointer — so silencing a finding never
 * needs a second vocabulary. Both spellings of a pointer read the same; {@see LintSafelist} owns
 * that, and every `lint.*.allow` gets it from here.
 */
final readonly class LintRuleOptions
{
    /**
     * @param  list<string>  $allow  subjects to skip, spelled as the rule's message spells them
     *                               (a pointer either bare or as a `#/…` fragment)
     */
    public function __construct(
        public bool $enabled = true,
        public array $allow = [],
    ) {}

    /** Whether any of the names this finding goes by is safelisted. */
    public function silences(?string ...$subjects): bool
    {
        return LintSafelist::matches($this->allow, ...$subjects);
    }
}
