<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract;

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

    /** The operation nobody documented, with the paths the contract does document for that method. */
    public static function undocumented(Exchange $exchange, ContractIndex $index, ?string $hint = null): string
    {
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

    /** Examples that do not satisfy the schema they sit beside. */
    public static function examples(ExampleReport $report): string
    {
        // Two different counts decide the grammar: the noun follows how many were checked, the verb
        // and the pronoun follow how many failed.
        $failed = count($report->findings);

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

        foreach ($report->findings as $finding) {
            $lines[] = '  '.PlainText::of($finding->label);
            $lines[] = '    at '.PlainText::of($finding->pointer);

            foreach (self::violationLines($finding->violations) as $line) {
                $lines[] = '  '.$line;
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
