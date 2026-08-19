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
 * Warns on an operation that publishes neither a summary nor a description — the one completeness
 * hole a reader of the document cannot work around, since nothing else says what the endpoint does.
 *
 * Deliberately operations only — routes and webhooks alike, since a webhook class has a docblock to
 * write in just as an action does. Parameters and schema properties were measured on the workbench and
 * fire on 40% and 98% of their populations, almost all of it where there is nothing to write (a
 * route-model-bound `{user}`, a column whose name is the whole story) — a rule that fires there
 * trains people to ignore the channel. Off by default for the same reason at a different scale: on
 * an application that documents nothing this fires once per operation, which is a backlog, not a
 * diagnostic.
 *
 * Diagnostics only, and pinned to run last so what it reads is what will be emitted.
 */
#[ExtensionOrder(priority: Priorities::LAST)]
final class MissingDescriptionLint implements DocumentTransformer
{
    public function __construct(
        private readonly LintRuleOptions $options = new LintRuleOptions(enabled: false),
    ) {}

    public function transform(UirDocumentDraft $document, DocumentContext $context): void
    {
        if (! $this->options->enabled) {
            return;
        }

        foreach (LintOperation::all($document->toArray()) as $operation) {
            if ($this->hasProse($operation) || $this->options->silences($operation->signature, $operation->operationId())) {
                continue;
            }

            $context->report(new Diagnostic(
                severity: Severity::Warning,
                code: 'lint.missing-description',
                message: sprintf('%s publishes neither a summary nor a description, so the document never says what it does.', $operation->signature),
                source: $operation->source(),
                help: sprintf(
                    'Give the %s a docblock — its first line becomes the summary and the rest the description — or write one in an overlay. If it is genuinely self-describing, safelist it under lint.descriptions.allow.',
                    $operation->webhook ? 'webhook class' : 'action',
                ),
            ));
        }
    }

    /** Either member, non-empty once trimmed — a summary of `" "` says as little as none. */
    private function hasProse(LintOperation $operation): bool
    {
        foreach (['summary', 'description'] as $member) {
            $value = $operation->operation[$member] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        return false;
    }
}
