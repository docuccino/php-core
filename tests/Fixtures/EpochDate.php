<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures;

use DateTimeImmutable;
use JsonSerializable;

/**
 * {@see SerialisingDate}'s sibling: the same declaration, stating the same interface, writing an
 * INTEGER. The pair is why `JsonSerializable` cannot be read as evidence of a wire format — nothing
 * short of the bytes tells the two apart.
 */
final class EpochDate extends DateTimeImmutable implements JsonSerializable
{
    public function jsonSerialize(): int
    {
        return $this->getTimestamp();
    }
}
