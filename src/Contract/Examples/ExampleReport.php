<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract\Examples;

/**
 * What the example audit found. `checked` matters as much as `findings`: "no example disagrees with its
 * schema" says nothing useful when the document carries no examples at all.
 *
 * `uncheckable` is the third answer, and it is not a finding: there was no schema to hold the example
 * to — the validator refused the one written there, or the document wrote none — so the audit knows
 * nothing about it either way. Keeping it apart from `findings` is what
 * lets a checker limitation be reported as one rather than as an example that contradicts its contract.
 * It is kept out of `checked` for the same reason — a denominator counting examples nobody could check
 * reads as having proved more than the audit did.
 */
final readonly class ExampleReport
{
    /**
     * @param  int  $checked  examples the validator actually judged, uncheckable ones excluded
     * @param  list<ExampleFinding>  $findings
     * @param  list<ExampleUncheckable>  $uncheckable
     */
    public function __construct(
        public int $checked,
        public array $findings,
        public array $uncheckable = [],
    ) {}

    public function ok(): bool
    {
        return $this->findings === [];
    }
}
