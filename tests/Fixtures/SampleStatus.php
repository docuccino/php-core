<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures;

/**
 * A string-backed enum whose name is fed to the type-string grammar as a bare identifier, so the parser's
 * answer for an enum written in a docblock can be told apart from its answer for a plain class.
 */
enum SampleStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
