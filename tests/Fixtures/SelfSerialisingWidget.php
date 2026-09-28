<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures;

use JsonSerializable;

/** States its own JSON form and drops `caption` when it is null, so the promoted property says nothing about the key. */
final class SelfSerialisingWidget implements JsonSerializable
{
    public function __construct(
        public int $id,
        public ?string $caption = null,
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->caption === null ? ['id' => $this->id] : ['id' => $this->id, 'caption' => $this->caption];
    }
}
