<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\Contracts;

use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Inference\DType\DType;

/**
 * One link in the type→schema chain (design §6): the first mapper whose {@see supports()}
 * returns true and whose {@see toSchema()} returns non-null wins; returning null defers to
 * the next mapper, so a user mapper registered `before` a built-in intercepts only the
 * classes it cares about.
 */
interface TypeToSchema
{
    public function supports(DType $type): bool;

    public function toSchema(DType $type, SchemaContext $context): ?SchemaResult;
}
