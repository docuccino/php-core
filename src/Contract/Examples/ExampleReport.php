<?php

declare(strict_types=1);

namespace Docuccino\Core\Contract\Examples;

/**
 * What the example audit found. `checked` matters as much as `findings`: "no example disagrees with its
 * schema" says nothing useful when the document carries no examples at all.
 *
 * `uncheckable` is the third answer, and it is not a finding: the validator refused the schema, so the
 * audit knows nothing about the example beside it either way. Keeping it apart from `findings` is what
 * lets a checker limitation be reported as one rather than as an example that contradicts its contract.
 */
final readonly class ExampleReport
{
    /**
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
