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
 * Flags an `anyOf` whose empty `{}` branch accepts anything, so the typed branches beside it add no
 * constraint — an honest widening that quietly erased the shape a consumer would otherwise validate
 * against. The shape itself is kept: the typed branch still tells a reader what the value usually is,
 * and dropping it would lose that.
 *
 * Diagnostics only, and pinned to run last so what it reads is what will be emitted.
 *
 * @phpstan-type VacuousUnion array{pointer: string, allEmpty: bool}
 */
#[ExtensionOrder(priority: Priorities::LAST)]
final class VacuousUnionLint implements DocumentTransformer
{
    /**
     * Keywords whose value is DATA rather than a schema. An example or a default may be any JSON at
     * all, including something shaped exactly like a union, so the walk stops at these.
     */
    private const DATA_KEYWORDS = ['enum', 'examples', 'example', 'default', 'const'];

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

            foreach (self::vacuousUnions($operation->operation, '') as $union) {
                $context->report(new Diagnostic(
                    severity: Severity::Info,
                    code: 'lint.vacuous-union',
                    message: sprintf(
                        $union['allEmpty']
                            ? '%s publishes an anyOf (at %s) whose every branch is an unconstrained {}, so the value validates as anything.'
                            : '%s publishes an anyOf (at %s) with an unconstrained {} branch, so its typed branches add no constraint — the value validates as anything.',
                        $operation->signature,
                        $union['pointer'],
                    ),
                    source: $operation->source(),
                    help: 'Pin the arm that recovered as "anything" — a return docblock or #[Response] on the action — or safelist the operation under lint.vacuous_union.allow.',
                ));
            }
        }
    }

    /**
     * JSON pointers (relative to the operation) of every `anyOf` that carries an empty branch beside
     * at least one other branch. A branch is empty when nothing but `x-` extension members constrain
     * it — provenance rides inside schemas, and it constrains nothing.
     *
     * The walk descends through schema subtrees only: an `x-` member and the value of a data-carrying
     * keyword ({@see DATA_KEYWORDS}) are skipped, because a literal value shaped like `{"anyOf": …}`
     * is an example of a union, not one.
     *
     * @param  array<array-key, mixed>  $node
     * @return list<VacuousUnion>
     */
    private static function vacuousUnions(array $node, string $pointer): array
    {
        $found = [];

        $anyOf = $node['anyOf'] ?? null;
        if (is_array($anyOf) && array_is_list($anyOf) && count($anyOf) > 1) {
            $branches = array_filter($anyOf, 'is_array');
            $empty = array_filter($branches, self::isUnconstrained(...));

            if ($empty !== []) {
                $found[] = ['pointer' => $pointer.'/anyOf', 'allEmpty' => count($empty) === count($anyOf)];
            }
        }

        foreach ($node as $key => $value) {
            $name = (string) $key;
            if (str_starts_with($name, 'x-') || in_array($name, self::DATA_KEYWORDS, true)) {
                continue;
            }

            if (is_array($value)) {
                $found = [...$found, ...self::vacuousUnions($value, $pointer.'/'.$name)];
            }
        }

        return $found;
    }

    /**
     * A branch nothing but `x-` members constrain. An annotation-only branch — `description`, `title`
     * — deliberately counts as CONSTRAINED: it says something a reader acts on, and treating it as
     * empty would spend the channel on unions nobody widened.
     *
     * @param  array<array-key, mixed>  $branch
     */
    private static function isUnconstrained(array $branch): bool
    {
        foreach (array_keys($branch) as $key) {
            if (! str_starts_with((string) $key, 'x-')) {
                return false;
            }
        }

        return true;
    }
}
