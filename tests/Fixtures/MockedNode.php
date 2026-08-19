<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures;

use Docuccino\Attributes\Mock;
use Docuccino\Core\Extensions\BuiltIn\ClassTypeToSchema;

/**
 * A plain PHP DTO carrying every `#[Mock]` shape at once — both halves that publish and every one that
 * cannot — so the fallback {@see ClassTypeToSchema} is the only mapper that can read them.
 *
 * `reference` is claimed by the class-level form AND by its own attribute, which is how the property
 * winning is pinned.
 */
#[Mock(faker: 'uuid', property: 'id')]
#[Mock(faker: 'word', property: 'reference')]
#[Mock(faker: 'word', property: 'not_a_property')]
#[Mock(faker: 'word')]
#[Mock(property: 'id')]
final readonly class MockedNode
{
    public function __construct(
        public string $id,
        #[Mock(faker: 'slug')]
        public string $reference,
        #[Mock(faker: 'safeEmail', seedGroup: 'person')]
        public string $email,
        #[Mock(seedGroup: 'person')]
        public string $name,
        #[Mock(faker: '   ')]
        public string $blank,
        #[Mock]
        public string $empty,
        #[Mock(faker: 'colorName', property: 'elsewhere')]
        public string $misdirected,
    ) {}
}
