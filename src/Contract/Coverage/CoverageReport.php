<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract\Coverage;

use Docuccino\Core\Contract\ContractIndex;
use Docuccino\Core\Support\PlainText;

/**
 * "Which documented responses and webhook deliveries did the suite never exercise?" — matched by stable
 * id, listed in the document's own order (paths first — path, the canonical method order, then ascending
 * status — then the webhooks), so the report is a function of the contract and the run, never of the
 * order tests happened to execute.
 *
 * Responses rather than operations, because a response is what the contract promises a client: a suite
 * that only ever asserts the happy path has proved nothing about the `422` somebody writes a `catch`
 * against, and a number counting operations calls that full coverage. A webhook's delivery is the same
 * promise pointing outward, and counts beside them. Both numbers are printed — only the response and
 * delivery one is compared to a floor.
 *
 * Ids, never paths: an id survives a path rename, so a renamed route reads as still covered rather
 * than as one operation appearing and another vanishing.
 */
final readonly class CoverageReport
{
    /**
     * @param  list<OperationCoverage>  $rows
     */
    private function __construct(public array $rows) {}

    /**
     * @param  list<string>  $exercised  what the run reached, as coverage log entries: an operation id
     *                                   with the status it answered, or a bare id where the run reached
     *                                   the operation without proving any response of it
     */
    public static function of(ContractIndex $index, array $exercised): self
    {
        $touched = [];
        $statuses = [];

        foreach ($exercised as $entry) {
            $parsed = CoverageLog::parse($entry);

            if ($parsed === null) {
                continue;
            }

            $touched[$parsed['id']] = true;

            if ($parsed['status'] !== null) {
                $statuses[$parsed['id']][] = $parsed['status'];
            }
        }

        $rows = [];
        foreach ($index->operations() as $operation) {
            $reached = $operation->id !== null && isset($touched[$operation->id]);

            // Which response a status was checked against is the operation's own grammar, so a coverage
            // row and a failure message can never disagree about where a 422 belonged.
            $seen = [];
            foreach ($operation->id === null ? [] : ($statuses[$operation->id] ?? []) as $status) {
                $key = $operation->responseKeyFor($status);

                if ($key !== null) {
                    $seen[$key] = true;
                }
            }

            $keys = $operation->responseKeys();

            $rows[] = new OperationCoverage(
                id: $operation->id,
                label: $operation->label(),
                exercised: $reached,
                responses: $keys === []
                    ? [new ResponseCoverage(null, $reached)]
                    : array_map(
                        static fn (string $key): ResponseCoverage => new ResponseCoverage($key, isset($seen[$key])),
                        $keys,
                    ),
                unreachable: $operation->unreachableResponseKeys(),
            );
        }

        // The outbound half, after the inbound one and in the index's own order. A webhook carries ONE
        // row, keyed `delivery`, rather than one per documented response: its `responses` are what the
        // RECEIVER answers, and nothing in the sending application's suite can exercise those — counting
        // them would publish a floor nobody could ever meet. The delivery is what a sender can prove.
        //
        // So a webhook's id carries no status and the row reads it as the delivery itself: the sender
        // records that id only where the payload was checked AGAINST the documented body and agreed,
        // never for having asserted about one — the same rule the statuses above are credited on.
        foreach ($index->webhooks() as $webhook) {
            $reached = $webhook->id !== null && isset($touched[$webhook->id]);

            $rows[] = new OperationCoverage(
                id: $webhook->id,
                label: $webhook->label(),
                exercised: $reached,
                responses: [new ResponseCoverage('delivery', $reached)],
            );
        }

        return new self($rows);
    }

    /**
     * Every row with something the run never reached — the listing, so an operation whose happy path is
     * covered and whose errors are not appears here with its errors named, and a webhook nothing
     * delivered appears with its `delivery`.
     *
     * @return list<OperationCoverage>
     */
    public function missing(): array
    {
        return array_values(array_filter($this->rows, static fn (OperationCoverage $row): bool => ! $row->complete()));
    }

    public function totalOperations(): int
    {
        return count($this->rows);
    }

    public function exercisedOperations(): int
    {
        return count(array_filter($this->rows, static fn (OperationCoverage $row): bool => $row->exercised));
    }

    public function totalResponses(): int
    {
        return array_sum(array_map(static fn (OperationCoverage $row): int => count($row->responses), $this->rows));
    }

    public function exercisedResponses(): int
    {
        return $this->totalResponses() - array_sum(array_map(
            static fn (OperationCoverage $row): int => count($row->unexercised()),
            $this->rows,
        ));
    }

    /**
     * Percentage of documented responses and webhook deliveries exercised. An empty document is fully
     * covered, vacuously.
     */
    public function percentage(): float
    {
        return $this->totalResponses() === 0 ? 100.0 : 100 * $this->exercisedResponses() / $this->totalResponses();
    }

    public function complete(): bool
    {
        return $this->missing() === [];
    }

    /**
     * Whether the run clears a floor, compared the way {@see percentage()} rounds for display so a
     * report that PRINTS 80% never fails an 80% floor.
     */
    public function meets(float $minimum): bool
    {
        return round($this->percentage(), 2) >= $minimum;
    }

    /**
     * A report a developer can paste into a pull request, and the body of the coverage assertion.
     * Passing $minimum names the floor that was missed and the honest measured value to move it to.
     * Labels, ids and status keys are the artifact's own strings, so all three go through
     * {@see PlainText} first.
     *
     * @param  string|null  $exportCommand  how this application exports, for the one remediation that needs
     *                                      naming a command — core cannot know, so the caller says.
     */
    public function render(?float $minimum = null, ?string $exportCommand = null): string
    {
        $missing = $this->missing();

        $lines = [
            sprintf(
                'Docuccino contract coverage: %d of %d documented responses and webhook deliveries exercised (%s%%%s).',
                $this->exercisedResponses(),
                $this->totalResponses(),
                self::number($this->percentage()),
                $minimum === null ? '' : sprintf(', floor %s%%', self::number($minimum)),
            ),
            sprintf(
                '%d of %d documented operations were reached at all%s.',
                $this->exercisedOperations(),
                $this->totalOperations(),
                $minimum === null ? '' : ' — the floor is measured against responses and deliveries, not operations',
            ),
        ];

        if ($missing !== []) {
            $lines = [...$lines, ...$this->listing($missing, $minimum, $exportCommand)];
        }

        // Last, and printed whether or not anything is missing: a document can be at 100% and still name
        // a response no run could ever have exercised, and that is exactly when the short denominator
        // needs explaining.
        return implode("\n", [...$lines, ...$this->unreachable()]);
    }

    /**
     * The "Never exercised" table and the two remediations that go under it.
     *
     * @param  non-empty-list<OperationCoverage>  $missing
     * @return list<string>
     */
    private function listing(array $missing, ?float $minimum, ?string $exportCommand): array
    {
        // Escape before measuring, or an escaped label is wider than the column it was padded to. And
        // measure in characters rather than bytes, or a label with an accent in it pads short.
        $labels = array_map(static fn (OperationCoverage $row): string => PlainText::of($row->label), $missing);
        $statuses = array_map(self::statuses(...), $missing);

        $labelWidth = max(array_map(self::characters(...), $labels));
        $statusWidth = max(array_map(self::characters(...), $statuses));

        $lines = ['', 'Never exercised:'];

        foreach ($missing as $index => $row) {
            $lines[] = '  '
                .$labels[$index].self::pad($labels[$index], $labelWidth)
                .'  '.$statuses[$index].self::pad($statuses[$index], $statusWidth)
                .'  '.($row->id === null ? '(no id)' : PlainText::of($row->id));
        }

        if ($minimum !== null) {
            $lines[] = '';
            $lines[] = sprintf(
                'Cover them, or — if this is the honest measured floor for now — move the floor to %s and ratchet it up from there.',
                self::number(floor($this->percentage())),
            );
        }

        $unidentified = count(array_filter($missing, static fn (OperationCoverage $row): bool => $row->id === null));

        if ($unidentified > 0) {
            $lines[] = '';
            $lines[] = sprintf(
                '%d of those carry no x-docuccino id, so nothing can record them as exercised. Export the artifact '.
                'as UIR rather than as OpenAPI with identities dropped%s.',
                $unidentified,
                $exportCommand === null ? '' : ' ('.$exportCommand.')',
            );
        }

        return $lines;
    }

    /**
     * The documented responses no status could ever name. They are in neither count — nothing can
     * resolve to one, so a denominator carrying one could never be filled and a 100% floor would be
     * unreachable forever — but a key silently missing from the report is how that stays a mystery, so
     * the report names each one and what a response key is allowed to be.
     *
     * @return list<string>
     */
    private function unreachable(): array
    {
        $named = [];
        foreach ($this->rows as $row) {
            foreach ($row->unreachable as $key) {
                $named[] = PlainText::of($row->label).' '.PlainText::of($key);
            }
        }

        if ($named === []) {
            return [];
        }

        return [
            '',
            sprintf(
                '%d documented %s named by a key no status can ever resolve to, so %s in neither count above: %s. '.
                'A response key is a three-digit status, an uppercase range (4XX), or default.',
                count($named),
                count($named) === 1 ? 'response is' : 'responses are',
                count($named) === 1 ? 'it is' : 'they are',
                implode(', ', $named),
            ),
        ];
    }

    /** The statuses of one operation the run never reached, as the column names them. */
    private static function statuses(OperationCoverage $row): string
    {
        $statuses = array_map(
            static fn (ResponseCoverage $response): ?string => $response->status,
            $row->unexercised(),
        );

        // A null status is the operation promising nothing a status could name, which is one row and
        // never a list — so there is nothing to join, and saying "404" for it would invent a promise.
        // It says which of the two reasons applies, or a document whose every response key is
        // unreachable would read "no responses documented" directly above the note naming them.
        if ($statuses === [null]) {
            return $row->unreachable === [] ? '(no responses documented)' : '(no response a status can name)';
        }

        return implode(', ', array_map(static fn (?string $status): string => PlainText::of((string) $status), $statuses));
    }

    /** The spaces that take an already-escaped column value out to its width. */
    private static function pad(string $value, int $width): string
    {
        return str_repeat(' ', max(0, $width - self::characters($value)));
    }

    /**
     * A label's width in characters. PCRE counts them without ext-mbstring, which core would
     * otherwise have to require for one aligned column.
     */
    private static function characters(string $value): int
    {
        return preg_match_all('/./u', $value) ?: strlen($value);
    }

    /** `100`, `87.5` — never `87.50`, and never locale-dependent. */
    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format(round($value, 2), 2, '.', ''), '0'), '.');
    }
}
