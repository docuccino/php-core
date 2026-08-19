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
 * Warns on an operationId a generated client cannot turn into a method name ({@see
 * OperationIdStyle}). Aimed at the consumer: they meet the id as a function they call, and a broken
 * one either fails codegen or arrives renamed to something nobody wrote.
 *
 * On by default because it cannot fire on anything Docuccino mints — both id strategies produce ids
 * this passes — so every finding is on a string somebody typed, in an `#[OperationId]`, a route name
 * or a `#[Webhook]` name, and can be typed differently.
 *
 * Diagnostics only, and pinned to run last so what it reads is what will be emitted.
 */
#[ExtensionOrder(priority: Priorities::LAST)]
final class OperationIdStyleLint implements DocumentTransformer
{
    public function __construct(
        private readonly LintRuleOptions $options = new LintRuleOptions,
    ) {}

    public function transform(UirDocumentDraft $document, DocumentContext $context): void
    {
        if (! $this->options->enabled) {
            return;
        }

        foreach (LintOperation::all($document->toArray()) as $operation) {
            $operationId = $operation->operation['operationId'] ?? null;
            if (! is_string($operationId)) {
                continue;
            }

            $problem = OperationIdStyle::problem($operationId);
            if ($problem === null || $this->options->silences($operation->signature, $operationId)) {
                continue;
            }

            $context->report(new Diagnostic(
                severity: Severity::Warning,
                code: 'lint.operation-id-style',
                message: sprintf('operationId "%s" on %s %s, so a generated client cannot name a method after it.', $operationId, $operation->signature, $problem),
                source: $operation->source(),
                help: self::help($operation),
            ));
        }
    }

    /**
     * Which lever renames it. A webhook is published under its `#[Webhook]` name and never reaches
     * the operation extensions, so `#[OperationId]` would do nothing there.
     */
    private static function help(LintOperation $operation): string
    {
        $lever = $operation->webhook
            ? 'Rename the #[Webhook] to a name built from letters, digits and . - _ @ — a webhook is published under its name.'
            : 'Give it an id built from letters, digits and . - _ @ with #[OperationId], or rename the route.';

        return $lever.' If your generator copes, safelist it under lint.operation_ids.allow.';
    }
}
