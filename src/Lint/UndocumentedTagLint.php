<?php

declare(strict_types=1);

namespace Docuccino\Core\Lint;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Contracts\DocumentTransformer;
use Docuccino\Core\Extensions\Document\UirDocumentDraft;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;

/**
 * Warns on a tag operations carry that the document never declares, so it reaches the reader as a
 * bare heading among tags that have a summary, a description and a place in the hierarchy.
 *
 * It says nothing at all unless the document declares tags: undeclared tags are the normal, correct
 * state for an application that never curated them, and the finding only means something once "the
 * others have descriptions" is true. Off besides that guard, because the mixed case it cannot see —
 * a few nav parents declared by hand while the rest derive from controller names — is a deliberate
 * shape, and firing once per derived tag there is exactly the noise the guard exists to avoid.
 *
 * Diagnostics only, and pinned to run last so what it reads is what will be emitted.
 */
#[ExtensionOrder(priority: Priorities::LAST)]
final class UndocumentedTagLint implements DocumentTransformer
{
    public function __construct(
        private readonly LintRuleOptions $options = new LintRuleOptions(enabled: false),
    ) {}

    public function transform(UirDocumentDraft $document, DocumentContext $context): void
    {
        if (! $this->options->enabled) {
            return;
        }

        $draft = $document->toArray();
        $declared = $this->declared($draft);
        if ($declared === []) {
            return;
        }

        // Keyed by tag so each is reported once, and carrying the first operation in signature order
        // that claims it — a named example the reader can go and look at.
        $undeclared = [];
        foreach (LintOperation::all($draft) as $operation) {
            foreach ($operation->tags() as $tag) {
                if (! in_array($tag, $declared, true) && ! isset($undeclared[$tag]) && ! $this->options->silences($tag)) {
                    $undeclared[$tag] = $operation->signature;
                }
            }
        }

        ksort($undeclared);

        foreach ($undeclared as $tag => $signature) {
            $context->report(new Diagnostic(
                severity: Severity::Warning,
                code: 'lint.undocumented-tag',
                message: sprintf('Operations are tagged "%s" (%s), which the document never declares, so it is published without the summary, description and parent the declared tags carry.', $tag, $signature),
                help: 'Add an entry for it to the document\'s tags.definitions, map it onto a tag you did declare with tags.map or #[Group], or safelist it under lint.tags.allow.',
            ));
        }
    }

    /**
     * The names the document's top-level `tags` declares.
     *
     * @param  array<string, mixed>  $document
     * @return list<string>
     */
    private function declared(array $document): array
    {
        $tags = $document['tags'] ?? null;
        if (! is_array($tags)) {
            return [];
        }

        $names = [];
        foreach ($tags as $tag) {
            $name = is_array($tag) ? ($tag['name'] ?? null) : null;
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }
}
