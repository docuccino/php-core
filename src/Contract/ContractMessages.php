<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract;

use Docuccino\Core\Contract\Examples\ExampleFinding;
use Docuccino\Core\Contract\Examples\ExampleReport;
use Docuccino\Core\Diff\Change;
use Docuccino\Core\Diff\Changeset;
use Docuccino\Core\Diff\ChangesetRenderer;
use Docuccino\Core\Support\PlainText;

/**
 * The failure text a contract check produces. One owner for the whole vocabulary, so the assertions,
 * a CLI and any other adapter phrase the same finding the same way.
 *
 * Every message here is a PRODUCT: a contract-testing tool is worth having only if the failure says
 * what to change. That is why provenance runs through all of them — which producer contributed the
 * shape, and from which file and line — and why each takes an adapter `$hint`, so the framework-shaped
 * half of the advice ("run this command") is added by whoever knows the command.
 *
 * Every value here came out of an artifact nobody re-read first, or off the wire, so all of them go
 * through {@see PlainText} on the way into a line — provenance and pointers as much as names, since a
 * `source.file` is a string the document merely claims. Same reason as {@see ChangesetRenderer}.
 */
final class ContractMessages
{
    /** Past this, the list stops being read and starts being scrolled. */
    private const int MAX_VIOLATIONS = 10;

    private const int MAX_PATHS = 8;

    /** An exchange that disagreed with the contract. */
    public static function exchange(ContractOperation $operation, Exchange $exchange, CheckResult $result): string
    {
        $violations = [];
        foreach ($result->failures() as $outcome) {
            foreach ($outcome->violations as $violation) {
                $violations[] = $violation;
            }
        }

        $lines = [
            sprintf('%s does not match the documented contract.', PlainText::of($exchange->label())),
            '',
            '  operation  '.PlainText::of($operation->label()).($operation->id === null ? '' : '  '.PlainText::of($operation->id)),
            '  status     '.$exchange->status,
            '',
        ];

        foreach (self::violationLines($violations) as $line) {
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * The operation nobody documented, with the paths the contract does document for that method.
     *
     * Unless the contract DOES document the path and the reference it stands behind is broken, which
     * is a different fact and the only one of the two a reader can act on: nothing was added or
     * removed, a `$ref` names a path item the document never defines.
     */
    public static function undocumented(Exchange $exchange, ContractIndex $index, ?string $hint = null): string
    {
        $unresolved = self::unresolvedPathItemFor($exchange->path, $index->unresolvedPaths());

        if ($unresolved !== null) {
            return self::withHint(self::unresolvedPathItem($exchange->label(), 'path', $unresolved[0], $unresolved[1]), $hint);
        }

        $candidates = [];
        foreach ($index->operations() as $operation) {
            if ($operation->method === strtoupper($exchange->method)) {
                $candidates[] = $operation->path;
            }
        }

        $shown = array_slice($candidates, 0, self::MAX_PATHS);
        $extra = count($candidates) - count($shown);

        $method = PlainText::of(strtoupper($exchange->method));
        $lines = [sprintf('%s is not documented.', PlainText::of($exchange->label())), ''];

        if ($shown === []) {
            $lines[] = sprintf('  The contract documents no %s operation at all.', $method);
        } else {
            $lines[] = sprintf('  The contract documents these %s paths:', $method);
            foreach ($shown as $candidate) {
                $lines[] = '    '.PlainText::of($candidate);
            }
            if ($extra > 0) {
                $lines[] = sprintf('    … and %d more.', $extra);
            }
        }

        $lines[] = '';
        $lines[] = '  The artifact predates this route, or the route is excluded from the document.';

        return self::withHint(implode("\n", $lines), $hint);
    }

    /** A dispatched payload that disagreed with the body its webhook documents. */
    public static function delivery(ContractWebhook $webhook, Outcome $outcome): string
    {
        $lines = [
            sprintf('The payload dispatched for %s does not match the documented contract.', PlainText::of($webhook->label())),
            '',
            '  webhook    '.PlainText::of($webhook->label()).($webhook->id === null ? '' : '  '.PlainText::of($webhook->id)),
            '',
        ];

        foreach (self::violationLines($outcome->violations) as $line) {
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * A payload no encoder would turn into bytes, which is not a payload the application could have
     * delivered either. The encoder's own words go through {@see PlainText} like everything else: they
     * are the one string in a delivery failure that came from neither the artifact nor the test.
     */
    public static function unreadableDelivery(ContractWebhook $webhook, string $reason, ?string $hint = null): string
    {
        return self::withHint(sprintf(
            'Docuccino cannot read the payload dispatched for %s as JSON: %s.',
            PlainText::of($webhook->label()),
            PlainText::of(rtrim(trim($reason), '.')),
        ), $hint);
    }

    /**
     * What a passing exchange check could not look at, or null where it looked at everything.
     * {@see unchecked()}.
     */
    public static function uncheckedExchange(Exchange $exchange, CheckResult $result): ?string
    {
        return self::unchecked($exchange->label(), $result->notes());
    }

    /** The same for a delivery. {@see unchecked()}. */
    public static function uncheckedDelivery(ContractWebhook $webhook, Outcome $outcome): ?string
    {
        return self::unchecked($webhook->label(), $outcome->note === null ? [] : [$outcome->note]);
    }

    /**
     * The webhook nobody documented, with the names the contract does publish — or, when the name is
     * there and the method is not, the methods it is published for.
     */
    public static function undocumentedWebhook(string $name, ?string $method, ContractIndex $index, ?string $hint = null): string
    {
        $unresolved = $index->unresolvedWebhooks()[$name] ?? null;

        if ($unresolved !== null) {
            return self::withHint(self::unresolvedPathItem(
                ($method === null ? '' : strtoupper($method).' ').'webhooks.'.$name,
                'webhook',
                $name,
                $unresolved,
            ), $hint);
        }

        $published = $index->webhooksNamed($name);

        $lines = [sprintf(
            '%swebhooks.%s is not documented.',
            $method === null ? '' : PlainText::of(strtoupper($method)).' ',
            PlainText::of($name),
        ), ''];

        if ($published !== []) {
            $lines[] = '  The contract publishes that webhook, for these methods:';
            foreach ($published as $webhook) {
                $lines[] = '    '.PlainText::of($webhook->label());
            }
        } else {
            $names = $index->webhookNames();
            $shown = array_slice($names, 0, self::MAX_PATHS);
            $extra = count($names) - count($shown);

            if ($shown === []) {
                $lines[] = '  The contract documents no webhook at all.';
            } else {
                $lines[] = '  The contract documents these webhooks:';
                foreach ($shown as $candidate) {
                    $lines[] = '    '.PlainText::of($candidate);
                }
                if ($extra > 0) {
                    $lines[] = sprintf('    … and %d more.', $extra);
                }
            }
        }

        $lines[] = '';
        $lines[] = '  The artifact predates this webhook, or the webhook is excluded from the document.';

        return self::withHint(implode("\n", $lines), $hint);
    }

    /**
     * A name published for more than one method, with no method said. Guessing one would check the
     * payload against a body it was never sent as.
     *
     * @param  list<ContractWebhook>  $candidates
     */
    public static function ambiguousWebhook(string $name, array $candidates, ?string $hint = null): string
    {
        $lines = [
            sprintf('webhooks.%s is documented for more than one method, so which body this payload answers to is not decidable.', PlainText::of($name)),
            '',
        ];

        foreach ($candidates as $candidate) {
            $lines[] = '  '.PlainText::of($candidate->label()).($candidate->id === null ? '' : '  '.PlainText::of($candidate->id));
        }

        return self::withHint(implode("\n", $lines), $hint);
    }

    /**
     * An artifact whose format cannot carry a webhook contract. Saying "not documented" here would be
     * false: the webhook may well be documented, in the artifact this one was downlevelled from.
     */
    public static function webhooksUnsupported(ContractIndex $index, ?string $hint = null): string
    {
        return self::withHint(implode("\n", [
            sprintf('The contract is OpenAPI %s, which defines no `webhooks` member.', PlainText::of($index->openApiVersion())),
            '',
            '  Every webhook the document had was dropped on the way down to 3.0, so there is nothing here',
            '  to check a delivery against. Assert against the UIR artifact, or a 3.1 or 3.2 export.',
        ]), $hint);
    }

    /**
     * Examples that do not satisfy the schema they sit beside, and the references the audit went
     * looking for examples behind and found nothing at.
     *
     * The two are counted apart in the headline and listed together underneath: a broken pointer is not
     * an example that disagrees with its schema, and folding it into that number would make the count
     * describe something the document does not contain.
     */
    public static function examples(ExampleReport $report): string
    {
        $unresolved = array_values(array_filter(
            $report->findings,
            static fn (ExampleFinding $finding): bool => $finding->unresolvedRef !== null,
        ));

        // Two different counts decide the grammar: the noun follows how many were checked, the verb
        // and the pronoun follow how many failed.
        $failed = count($report->findings) - count($unresolved);

        $lines = [
            sprintf(
                '%d of %d documented example%s %s not match the schema beside %s.',
                $failed,
                $report->checked,
                $report->checked === 1 ? '' : 's',
                $failed === 1 ? 'does' : 'do',
                $failed === 1 ? 'it' : 'them',
            ),
            '',
        ];

        if ($unresolved !== []) {
            $lines[] = sprintf(
                '%d reference%s the contract does not define, so nothing behind %s was read at all.',
                count($unresolved),
                count($unresolved) === 1 ? ' names something' : 's name something',
                count($unresolved) === 1 ? 'it' : 'them',
            );
            $lines[] = '';
        }

        foreach ($report->findings as $finding) {
            $lines[] = '  '.PlainText::of($finding->label);
            $lines[] = '    at '.PlainText::of($finding->pointer);

            foreach (self::violationLines($finding->violations) as $line) {
                $lines[] = '  '.$line;
            }
        }

        // An example nobody could check is not a passing example, and leaving it out of the message
        // entirely is how a report comes to claim more than it proved.
        if ($report->uncheckable !== []) {
            $lines[] = '';
            $lines[] = sprintf(
                '%d more could not be checked at all — no schema this could be checked against sits beside %s, so this says nothing about %s either way.',
                count($report->uncheckable),
                count($report->uncheckable) === 1 ? 'it' : 'them',
                count($report->uncheckable) === 1 ? 'it' : 'them',
            );

            foreach ($report->uncheckable as $uncheckable) {
                $lines[] = '';
                $lines[] = '  '.PlainText::of($uncheckable->label);
                $lines[] = '    at '.PlainText::of($uncheckable->pointer);
                $lines[] = '      '.PlainText::of($uncheckable->reason);
                $lines[] = '      schema   '.PlainText::of($uncheckable->schemaPointer);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Breaking changes against a committed artifact. The changeset is rendered by core's own
     * {@see ChangesetRenderer}, so this reads identically to `docuccino:diff`; the provenance block is
     * what the renderer cannot know — who wrote the node that broke.
     */
    public static function breaking(Changeset $changeset, ?ContractIndex $new, ?ContractIndex $old, ?string $hint = null): string
    {
        $breaking = $changeset->breakingChanges();

        $lines = [
            sprintf(
                'The current document makes %d breaking change%s to the committed contract.',
                count($breaking),
                count($breaking) === 1 ? '' : 's',
            ),
            '',
            rtrim((new ChangesetRenderer)->render($changeset), "\n"),
        ];

        foreach (self::trailLines($breaking, $new, $old) as $line) {
            $lines[] = $line;
        }

        return self::withHint(implode("\n", $lines), $hint);
    }

    /**
     * A committed artifact whose bytes no longer match a fresh build. Determinism is what makes the
     * byte comparison meaningful, and it is also why the semantic diff can be shown beside it: identical
     * inputs produce identical bytes, so differing bytes always have a cause worth naming.
     */
    public static function stale(string $path, ?Changeset $changeset, ?ContractIndex $new, ?ContractIndex $old, ?string $hint = null): string
    {
        $lines = [sprintf('%s is out of date.', PlainText::of($path)), ''];

        if ($changeset === null) {
            $lines[] = '  Its bytes differ from what this code produces, and it is not a document that can be';
            $lines[] = '  compared semantically, so there is no change list to show.';

            return self::withHint(implode("\n", $lines), $hint);
        }

        if ($changeset->isEmpty()) {
            $lines[] = '  The contract itself is unchanged — the artifact differs only in bytes the emitters';
            $lines[] = '  control, so re-exporting is all it needs.';

            return self::withHint(implode("\n", $lines), $hint);
        }

        $lines[] = '  What changed since it was written:';
        $lines[] = '';
        $lines[] = rtrim(self::indent((new ChangesetRenderer)->render($changeset)), "\n");

        foreach (self::trailLines($changeset->changes, $new, $old) as $line) {
            $lines[] = $line;
        }

        return self::withHint(implode("\n", $lines), $hint);
    }

    /**
     * A pass that proved less than it looks like it did, as ONE line, or null where there is nothing to
     * say.
     *
     * A check that could not read what the document published passes with a note rather than passing
     * silently — a `text/csv` body, a header documented with no schema — and a note nobody is told is
     * how a suite comes to believe it has contract coverage it does not have. One line rather than the
     * block a failure gets, because this travels on a run's warning channel, where most runners show the
     * first line and truncate: the subject frames it and the finding follows immediately.
     *
     * @param  list<string>  $notes
     */
    private static function unchecked(string $subject, array $notes): ?string
    {
        $kept = array_values(array_filter($notes, static fn (string $note): bool => trim($note) !== ''));

        if ($kept === []) {
            return null;
        }

        return sprintf(
            '%s passed, but part of the contract was not checked: %s.',
            PlainText::of($subject),
            implode('; ', array_map(PlainText::of(...), $kept)),
        );
    }

    /**
     * The provenance behind a list of changes, as message lines. Empty when nothing on either side
     * recorded any — an OpenAPI artifact carries none, and saying nothing beats saying "unknown" once
     * per change.
     *
     * @param  list<Change>  $changes
     * @return list<string>
     */
    private static function trailLines(array $changes, ?ContractIndex $new, ?ContractIndex $old): array
    {
        $trails = [];

        foreach ($changes as $change) {
            // A removal only exists on the OLD side; everything else is in the new document. Read the
            // new one first — the current source is where the reader has to go and make the change.
            $trail = $new?->provenanceOf($change->id) ?? ProvenanceTrail::none();

            if ($trail->isEmpty()) {
                $trail = $old?->provenanceOf($change->id) ?? ProvenanceTrail::none();
            }

            if (! $trail->isEmpty()) {
                $trails[] = [$change, $trail];
            }
        }

        if ($trails === []) {
            return [];
        }

        $lines = ['', '  Where those changes came from:'];

        foreach (array_slice($trails, 0, self::MAX_VIOLATIONS) as [$change, $trail]) {
            /** @var Change $change */
            /** @var ProvenanceTrail $trail */
            $lines[] = '    '.PlainText::of($change->path);
            foreach ($trail->lines() as $record) {
                $lines[] = '      '.PlainText::of($record);
            }
        }

        return $lines;
    }

    /**
     * @param  list<Violation>  $violations
     * @return list<string>
     */
    private static function violationLines(array $violations): array
    {
        $lines = [];

        foreach (array_slice($violations, 0, self::MAX_VIOLATIONS) as $violation) {
            $lines[] = '  '.PlainText::of($violation->where());
            $lines[] = '    '.PlainText::of($violation->message);

            if ($violation->schemaPointer !== '') {
                $lines[] = '    schema   '.PlainText::of($violation->schemaPointer);
            }

            foreach ($violation->provenance->lines() as $record) {
                $lines[] = '    from     '.PlainText::of($record);
            }

            $lines[] = '';
        }

        $extra = count($violations) - self::MAX_VIOLATIONS;

        if ($extra > 0) {
            $lines[] = sprintf('  … and %d more.', $extra);
            $lines[] = '';
        }

        return $lines;
    }

    /**
     * Which unresolved path item a concrete request path stands behind, as `[template, reference]`,
     * or null. Templates are tried in sorted order and the most specific match wins, exactly as
     * {@see ContractIndex::match()} chooses between two that both bind — otherwise the message could
     * name a different path item from the one the request would have been checked against.
     *
     * @param  array<string, string>  $unresolved  template => the reference
     * @return array{0: string, 1: string}|null
     */
    private static function unresolvedPathItemFor(string $path, array $unresolved): ?array
    {
        $best = null;
        $bestMask = '';

        foreach ($unresolved as $template => $reference) {
            $parsed = PathTemplate::parse($template);

            if ($parsed->bind($path) === null) {
                continue;
            }

            $mask = $parsed->literalMask();
            if ($best === null || $mask > $bestMask) {
                $best = [$template, $reference];
                $bestMask = $mask;
            }
        }

        return $best;
    }

    /**
     * What a path item behind a reference that lands nowhere says. One wording for both halves: a
     * `$ref` naming nothing is the same broken document inbound and outbound, and the reader's next
     * move — define the component or fix the pointer — is the same too.
     */
    private static function unresolvedPathItem(string $subject, string $kind, string $name, string $reference): string
    {
        return implode("\n", [
            sprintf('%s is documented behind a reference the contract does not define.', PlainText::of($subject)),
            '',
            sprintf('  %-10s %s', $kind, PlainText::of($name)),
            '  reference  '.PlainText::of($reference),
            '',
            '  Nothing defines that path item, so the contract describes no operation there.',
        ]);
    }

    private static function indent(string $text): string
    {
        return implode("\n", array_map(
            static fn (string $line): string => $line === '' ? '' : '    '.$line,
            explode("\n", $text),
        ));
    }

    private static function withHint(string $message, ?string $hint): string
    {
        return $hint === null ? $message : $message."\n\n  ".$hint;
    }
}
