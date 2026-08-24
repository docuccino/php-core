<?php

declare(strict_types=1);

namespace Docuccino\Core\Diagnostics;

/**
 * How loudly a {@see Diagnostic} speaks. The four are totally ordered by {@see rank()}, which is the
 * one place that order is written down — CLI gates and diagnostic sorting both read it.
 *
 * `info` is the recovery channel: "the document is vaguer than your code, and here is where". It is
 * quieter than `warning` because widening is a correct outcome, not a defect.
 *
 * `hint` is quieter still: a note about the analysis rather than about the document. Nothing built-in
 * emits one today — it is the rung an extension picks for that kind of note, and it is enumerated in
 * the published UIR schema, so it stays whether or not we use it.
 */
enum Severity: string
{
    case Error = 'error';
    case Warning = 'warning';
    case Info = 'info';
    case Hint = 'hint';

    /** Higher is louder. */
    public function rank(): int
    {
        return match ($this) {
            self::Error => 3,
            self::Warning => 2,
            self::Info => 1,
            self::Hint => 0,
        };
    }

    /** True when this is $floor or louder — what a severity gate asks. */
    public function atLeast(self $floor): bool
    {
        return $this->rank() >= $floor->rank();
    }
}
