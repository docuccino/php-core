<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures;

use Docuccino\Attributes\Hidden;
use Docuccino\Core\Extensions\BuiltIn\ClassTypeToSchema;

/**
 * A plain PHP DTO hiding one property with the property-level `#[Hidden]` and another with the
 * class-level deny-list form — both of which only {@see ClassTypeToSchema} can honour for a DTO that
 * reaches no integration mapper.
 */
#[Hidden('password_hash')]
final readonly class HiddenPropertyNode
{
    public function __construct(
        public int $id,
        #[Hidden]
        public string $internal_score,
        public string $password_hash,
    ) {}
}
