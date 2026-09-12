<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures;

use DateTimeImmutable;
use JsonSerializable;

/**
 * A date-time stating its own JSON form, in the shape every framework's date class states one: a
 * subclass of PHP's own, declaring `jsonSerialize()`. This one happens to write RFC 3339; {@see
 * EpochDate} is the identical declaration writing an integer, and the pair is the whole reason the
 * mapper reads its answer off bytes it has seen rather than off the interface.
 */
final class SerialisingDate extends DateTimeImmutable implements JsonSerializable
{
    public const FORMAT = 'Y-m-d\TH:i:s.u\Z';

    public function jsonSerialize(): string
    {
        return $this->format(self::FORMAT);
    }
}
