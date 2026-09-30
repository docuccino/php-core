<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures;

use Docuccino\Attributes\CaseDescription;

/**
 * A backed enum whose first case carries a `#[CaseDescription]` PHP cannot construct — an `int` where
 * the attribute takes a string — above a docblock that says what the case means. Only ever reflected.
 */
enum UnreadableCaseStatus: string
{
    /** Still being written. */
    /* @phpstan-ignore argument.type (the wrong argument type IS the fixture) */
    #[CaseDescription(5)]
    case Draft = 'draft';

    #[CaseDescription('Visible to everyone.')]
    case Live = 'live';
}
