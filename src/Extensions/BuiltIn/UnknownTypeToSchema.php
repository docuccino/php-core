<?php

declare(strict_types=1);

namespace Docuccino\Core\Extensions\BuiltIn;

use Docuccino\Core\Extensions\Contracts\SchemaContext;
use Docuccino\Core\Extensions\Contracts\TypeToSchema;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Schema\SchemaResult;
use Docuccino\Core\Inference\DType\CallableT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\NeverT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\DType\VoidT;

/**
 * The terminal mapper: unresolvable, callable, void and never types all become an open `{}`
 * schema at low confidence (design §5 — `UnknownT → {}` + low-confidence provenance). Pinned
 * last in the chain via {@see ExtensionOrder} so anything specific wins first.
 */
#[ExtensionOrder(priority: Priorities::LAST)]
final class UnknownTypeToSchema implements TypeToSchema
{
    public function supports(DType $type): bool
    {
        return $type instanceof UnknownT
            || $type instanceof CallableT
            || $type instanceof VoidT
            || $type instanceof NeverT;
    }

    public function toSchema(DType $type, SchemaContext $context): SchemaResult
    {
        return new SchemaResult([], 0.1);
    }
}
