<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures;

use Docuccino\Attributes\Description;

/**
 * A backed enum that describes ITSELF to a consumer, with a class docblock — this one — saying what
 * only a maintainer wants. An enum is a class, so the pair is the same pair {@see DescribedNode} makes.
 */
#[Description(text: 'How far through review a submission has got.')]
enum DescribedStatus: string
{
    case Draft = 'draft';

    case Published = 'published';
}
