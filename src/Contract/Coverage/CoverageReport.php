<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract\Coverage;

use Docuccino\Core\Contract\ContractIndex;
use Docuccino\Core\Support\PlainText;

/**
 * "Which documented operations did the suite never exercise?" — matched by stable id, listed in the
 * document's own order (path, then the canonical method order), so the report is a function of the
 * contract and the run, never of the order tests happened to execute.
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
     * @param  list<string>  $exercised  operation ids the run touched
     */
    public static function of(ContractIndex $index, array $exercised): self
    {
        $seen = array_fill_keys($exercised, true);

        $rows = [];
        foreach ($index->operations() as $operation) {
            $rows[] = new OperationCoverage(
                id: $operation->id,
                label: $operation->label(),
                exercised: $operation->id !== null && isset($seen[$operation->id]),
            );
        }

        return new self($rows);
    }

    /** @return list<OperationCoverage> */
    public function missing(): array
    {
        return array_values(array_filter($this->rows, static fn (OperationCoverage $row): bool => ! $row->exercised));
    }

    public function total(): int
    {
        return count($this->rows);
    }

    public function exercisedCount(): int
    {
        return $this->total() - count($this->missing());
    }

    /** Percentage of documented operations exercised. An empty document is fully covered, vacuously. */
    public function percentage(): float
    {
        return $this->total() === 0 ? 100.0 : 100 * $this->exercisedCount() / $this->total();
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
     * Labels and ids are the artifact's own strings, so both go through {@see PlainText} first.
     */
    /**
     * @param  string|null  $exportCommand  how this application exports, for the one remediation that needs
     *                                      naming a command — core cannot know, so the caller says.
     */
    public function render(?float $minimum = null, ?string $exportCommand = null): string
    {
        $missing = $this->missing();

        $lines = [sprintf(
            'Docuccino contract coverage: %d of %d documented operations exercised (%s%%%s).',
            $this->exercisedCount(),
            $this->total(),
            self::number($this->percentage()),
            $minimum === null ? '' : sprintf(', floor %s%%', self::number($minimum)),
        )];

        if ($missing === []) {
            return $lines[0];
        }

        // Escape before measuring, or an escaped label is wider than the column it was padded to. And
        // measure in characters rather than bytes, or a label with an accent in it pads short.
        $labels = array_map(static fn (OperationCoverage $row): string => PlainText::of($row->label), $missing);
        $width = max(array_map(self::characters(...), $labels));

        $lines[] = '';
        $lines[] = 'Never exercised:';

        foreach ($missing as $index => $row) {
            $pad = str_repeat(' ', max(0, $width - self::characters($labels[$index])));
            $lines[] = '  '.$labels[$index].$pad.'  '.($row->id === null ? '(no id)' : PlainText::of($row->id));
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

        return implode("\n", $lines);
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
