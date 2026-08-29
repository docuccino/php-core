<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures;

use Docuccino\Attributes\BodyParameter;
use Docuccino\Attributes\Description;
use Docuccino\Attributes\Hidden;
use Docuccino\Attributes\Mock;
use Docuccino\Attributes\SchemaId;
use Docuccino\Attributes\SchemaName;
use JsonSerializable;

/**
 * Every class-target attribute a type IS read for, plus a foreign one — so the "read nowhere" report
 * has to stay silent about all of it. Only ever reflected.
 */
#[Description(text: 'A described node.')]
#[SchemaName('HonouredNode')]
#[SchemaId('node')]
#[Hidden('secret')]
#[Mock(seedGroup: 'node')]
#[BodyParameter(name: 'name')]
#[\Attribute]
final class HonouredDeclarationsNode implements JsonSerializable
{
    public string $name = '';

    public function jsonSerialize(): mixed
    {
        return ['name' => $this->name];
    }
}
