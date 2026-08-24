<?php

declare(strict_types=1);

namespace Docuccino\Core\Lint;

use Docuccino\Core\Contract\ContractIndex;
use Docuccino\Core\Contract\Examples\ExampleAudit;
use Docuccino\Core\Contract\Examples\ExampleFinding;
use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Contracts\DocumentTransformer;
use Docuccino\Core\Extensions\Document\UirDocumentDraft;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;

/**
 * The build-time half of {@see ExampleAudit}: every example the finished document publishes, held to
 * the schema it sits beside.
 *
 * The audit itself is also a test-suite assertion, and for a while that was the only way to reach it —
 * which meant an example contradicting its own type shipped silently unless an application happened to
 * opt in. An example is the one part of a document a consumer copies verbatim, and the one part
 * inference cannot be wrong about on its own: it is there because somebody wrote it. So the check runs
 * on every build, and the author hears about it where they will actually look.
 *
 * Diagnostics only — it never touches the document. Pinned to run last so the pointers it publishes are
 * the pointers the emitted document has, and so it sees a body already hoisted into a shared component
 * rather than the inline copy.
 */
#[ExtensionOrder(priority: Priorities::LAST)]
final class ExampleSchemaLint implements DocumentTransformer
{
    /** Past this the message stops being a diagnostic and starts being a report. */
    private const int REASONS = 3;

    public function __construct(
        private readonly LintRuleOptions $options = new LintRuleOptions,
    ) {}

    public function transform(UirDocumentDraft $document, DocumentContext $context): void
    {
        if (! $this->options->enabled) {
            return;
        }

        $report = (new ExampleAudit(ContractIndex::fromArray($document->toArray())))->run();

        foreach ($report->findings as $finding) {
            if ($this->options->silences($finding->pointer, $finding->label)) {
                continue;
            }

            $context->report(new Diagnostic(
                severity: Severity::Warning,
                code: 'lint.example-mismatch',
                message: sprintf(
                    'The example at %s does not satisfy the schema beside it: %s.',
                    $finding->pointer,
                    self::reasons($finding),
                ),
                help: 'A consumer copies an example out of the document and sends it back, so one the '
                    .'schema rejects is worse than none. Correct the example, or widen the schema if the '
                    .'example is what the API really accepts — and where the example is right and the '
                    .'schema merely under-describes it, accept the pointer under lint.examples.allow.',
            ));
        }
    }

    /**
     * Why it failed, in the validator's own words. The provenance the audit collects alongside names
     * files, which a diagnostic must not carry — a build's output would then depend on the machine that
     * produced it — so only the messages and their pointers are read.
     */
    private static function reasons(ExampleFinding $finding): string
    {
        $reasons = [];

        foreach ($finding->violations as $violation) {
            $at = $violation->pointer === '' ? '' : ' at '.$violation->pointer;
            $reason = rtrim($violation->message, '.').$at;

            if (! in_array($reason, $reasons, true)) {
                $reasons[] = $reason;
            }
        }

        $kept = array_slice($reasons, 0, self::REASONS);
        $rest = count($reasons) - count($kept);

        return implode('; ', $kept).($rest > 0 ? sprintf(' (and %d more)', $rest) : '');
    }
}
