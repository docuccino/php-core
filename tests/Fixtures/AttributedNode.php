<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures;

use Docuccino\Attributes\SchemaId;
use Docuccino\Attributes\SchemaName;
use Docuccino\Core\Extensions\BuiltIn\ClassTypeToSchema;

/**
 * A plain PHP DTO — no spatie Data, no Eloquent, no resource — so the framework-agnostic
 * {@see ClassTypeToSchema} is the only mapper that will handle it,
 * and the attributes have to be read there or nowhere.
 */
#[SchemaName('RenamedNode')]
#[SchemaId('node.v1')]
final readonly class AttributedNode
{
    public function __construct(
        public int $id,
    ) {}
}
