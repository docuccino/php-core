<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures;

use Docuccino\Attributes\SchemaId;
use Docuccino\Attributes\SchemaName;

/**
 * A backed enum whose component name and identity PHP cannot construct — an `int` where each attribute
 * takes a `string`. Read only where the enum hoists to a component. Only ever reflected.
 */
/* @phpstan-ignore argument.type (the wrong argument type IS the fixture) */
#[SchemaName(123)]
/* @phpstan-ignore argument.type (the wrong argument type IS the fixture) */
#[SchemaId(456)]
enum UnreadableIdentityStatus: string
{
    case Draft = 'draft';
    case Live = 'live';
}
