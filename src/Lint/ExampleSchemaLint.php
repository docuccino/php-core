<?php

declare(strict_types=1);

namespace Docuccino\Core\Lint;

use Docuccino\Core\Canonical\Canonicalizer;
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
 * Diagnostics only — it never touches the document. Pinned to run last so it sees a body already
 * hoisted into a shared component rather than the inline copy, and it audits the CANONICAL form of the
 * draft ({@see published()}) so what it holds to the schema is what the artifact will carry.
 *
 * A schema the validator will not read is reported as `lint.example-uncheckable` rather than thrown: a
 * lint runs on every build, so one that can end a build has taken the whole document hostage to a
 * check nobody asked for. It names the schema, which is the half to go and look at.
 *
 * A response or body the audit could not reach at all, because the `$ref` standing there names nothing
 * the document defines, is `lint.unresolved-reference` — a defect in the document rather than in an
 * example, and the one the author must fix first, since everything under that node went unread.
 */
#[ExtensionOrder(priority: Priorities::LAST)]
final class ExampleSchemaLint implements DocumentTransformer
{
    /** Past this the message stops being a diagnostic and starts being a report. */
    private const int REASONS = 3;

    public function __construct(
        private readonly LintRuleOptions $options = new LintRuleOptions,
        private readonly Canonicalizer $canonicalizer = new Canonicalizer,
    ) {}

    public function transform(UirDocumentDraft $document, DocumentContext $context): void
    {
        if (! $this->options->enabled) {
            return;
        }

        $report = (new ExampleAudit(ContractIndex::fromArray($this->published($document->toArray()))))->run();

        foreach ($report->findings as $finding) {
            // A finding with no example behind it: the audit went looking and the pointer named
            // nothing. Saying an example failed its schema would describe something the document does
            // not contain, and the reader's fix is a different one. It is also raised BEFORE the
            // allow-list is consulted — that list accepts an example a schema under-describes, and a
            // pointer at nothing is not an example anybody decided to keep. `diagnostics.accept` is
            // where a code nobody can act on goes.
            if ($finding->unresolvedRef !== null) {
                $context->report(new Diagnostic(
                    severity: Severity::Warning,
                    code: 'lint.unresolved-reference',
                    message: sprintf(
                        'The node at %s is a `$ref` to `%s`, which the document does not define.',
                        $finding->pointer,
                        $finding->unresolvedRef,
                    ),
                    help: 'Nothing can read what that node promises — not a client generator, not a '
                        .'viewer, not a contract test — so the schema and the examples under it went '
                        .'unchecked too. Define the component the pointer names, or correct the '
                        .'pointer; an overlay that renamed or removed a component is the usual cause.',
                ));

                continue;
            }

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

        foreach ($report->uncheckable as $site) {
            if ($this->options->silences($site->pointer, $site->label)) {
                continue;
            }

            $context->report(new Diagnostic(
                severity: Severity::Warning,
                code: 'lint.example-uncheckable',
                message: sprintf(
                    'The example at %s was not checked: the validator would not read the schema at %s — %s.',
                    $site->pointer,
                    $site->schemaPointer,
                    $site->reason,
                ),
                help: 'The example may be right or wrong; this build does not know. The schema is the '
                    .'half to look at — the pointer names it — and a schema no validator will read is a '
                    .'bug in Docuccino rather than in the application, so it is worth reporting. '
                    .'lint.examples.allow accepts the pointer meanwhile.',
            ));
        }
    }

    /**
     * The draft in the form the emitters publish it in.
     *
     * A transformer is handed the assembled document as PHP arrays, and an array cannot tell an empty
     * object from an empty list. {@see Canonicalizer} is the one place that decides which is which on
     * the way out — the empty schema an `array<string, mixed>` puts under `additionalProperties`
     * becomes `{}` there, and nowhere earlier — so a check reading the draft directly is reading a
     * document nobody ships, and can report a finding, or refuse a schema, about a shape that never
     * reaches the artifact. It also sorts, so the pointers a finding names are the artifact's own.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function published(array $document): array
    {
        return $this->canonicalizer->canonicalize($document);
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
