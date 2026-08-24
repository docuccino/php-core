<?php

declare(strict_types=1);

namespace Docuccino\Core\Lint;

use Docuccino\Core\Diagnostics\Diagnostic;
use Docuccino\Core\Diagnostics\Severity;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Contracts\DocumentTransformer;
use Docuccino\Core\Extensions\Document\UirDocumentDraft;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;

/**
 * Flags an operation whose document never says exactly one thing about its redirect: either the `3XX`
 * range stands alone, so a client cannot tell a permanent move from a temporary one, or the range stands
 * BESIDE a concrete 3xx, so the document says the code is that one and that it may be any other.
 *
 * It asks the question of the FINISHED document rather than of whichever producer first noticed the
 * redirect. That is the whole point of it living here: the range is what inference proves when a return
 * site names no code, and an attribute may name one afterwards — retracting the range as it goes
 * ({@see OperationDraft::supersedeStatusRange()}) — while an overlay lands after the document is built
 * and can only ADD the code, leaving the range for its author to remove. Reading the outcome is what
 * tells those two apart; a producer firing off its own findings would tell an author who pinned the
 * code to pin it again.
 *
 * Diagnostics only, and pinned to run last so what it reads is what will be emitted.
 */
#[ExtensionOrder(priority: Priorities::LAST)]
final class UnpinnedRedirectLint implements DocumentTransformer
{
    private const string RANGE = OperationDraft::REDIRECT_RANGE;

    private const string CONCRETE = '/^3\d\d$/D';

    public function __construct(
        private readonly LintRuleOptions $options = new LintRuleOptions,
    ) {}

    public function transform(UirDocumentDraft $document, DocumentContext $context): void
    {
        if (! $this->options->enabled) {
            return;
        }

        foreach (LintOperation::all($document->toArray()) as $operation) {
            if ($this->options->silences($operation->signature, $operation->operationId())) {
                continue;
            }

            $pinned = self::pinnedCodes($operation->operation);
            if ($pinned === null) {
                continue;
            }

            $context->report(new Diagnostic(
                severity: Severity::Info,
                code: 'lint.unpinned-redirect',
                message: $pinned === []
                    ? sprintf(
                        '%s publishes its redirect under the %s range, so the document never says which redirect it is.',
                        $operation->signature,
                        self::RANGE,
                    )
                    : sprintf(
                        '%s publishes the %s range beside %s, so the document says the redirect is that code AND that it may be any other 3xx.',
                        $operation->signature,
                        self::RANGE,
                        implode(' and ', $pinned),
                    ),
                source: $operation->source(),
                help: $pinned === []
                    ? 'Name the code the endpoint answers with — #[Response(302)] on the action, which retires the '
                        .'range as it declares the code. A conditional redirect declares each code it answers with; one '
                        .'that is genuinely unknowable is accepted under lint.unpinned_redirect.allow.'
                    : 'The code and the range cannot both be true, so one of them has to go. A code declared in an '
                        .'overlay needs a second action removing the '.self::RANGE.' response, because an overlay is '
                        .'applied after the document is built and cannot retract what the build published; '
                        .'#[Response(302)] on the action does both at once. An operation that really means both is '
                        .'accepted under lint.unpinned_redirect.allow.',
            ));
        }
    }

    /**
     * The concrete 3xx statuses published beside the range, byte-sorted — `[]` where the range stands
     * alone, and null where there is no range to say anything about.
     *
     * @param  array<string, mixed>  $operation
     * @return ?list<string>
     */
    private static function pinnedCodes(array $operation): ?array
    {
        $responses = $operation['responses'] ?? null;
        if (! is_array($responses) || ! array_key_exists(self::RANGE, $responses)) {
            return null;
        }

        $pinned = [];
        foreach (array_keys($responses) as $status) {
            if (preg_match(self::CONCRETE, (string) $status) === 1) {
                $pinned[] = (string) $status;
            }
        }

        sort($pinned, SORT_STRING);

        return $pinned;
    }
}
