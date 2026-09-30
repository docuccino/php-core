<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures;

use JsonSerializable;
use Serializable;

/**
 * A value PHP serialises in its oldest form, through the pre-8.1 interface alone, answering null, which
 * `serialize()` writes as `N;` exactly as it writes null; and one `json_encode` would write through
 * {@see jsonSerialize()}. It notes each call. Declaring one is deprecated, so the tests that load it silence that.
 */
final class LegacySerializedValue implements JsonSerializable, Serializable
{
    /** @var list<string> */
    public array $calls = [];

    public function serialize(): ?string
    {
        $this->calls[] = 'serialize';

        return null;
    }

    public function unserialize(string $data): void {}

    public function jsonSerialize(): string
    {
        $this->calls[] = 'jsonSerialize';

        return 'written';
    }
}
