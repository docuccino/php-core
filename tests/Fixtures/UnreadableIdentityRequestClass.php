<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures;

use Docuccino\Attributes\SchemaId;
use Docuccino\Attributes\SchemaName;

/**
 * A request source class whose component name and identity PHP cannot construct — an `int` where each
 * attribute takes a `string`. Read only where a component is minted for it. Only ever reflected.
 */
/* @phpstan-ignore argument.type (the wrong argument type IS the fixture) */
#[SchemaName(123)]
/* @phpstan-ignore argument.type (the wrong argument type IS the fixture) */
#[SchemaId(456)]
final class UnreadableIdentityRequestClass {}
