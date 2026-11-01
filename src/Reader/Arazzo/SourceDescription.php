<?php

declare(strict_types=1);

namespace Docuccino\Core\Reader\Arazzo;

/**
 * One of the descriptions an Arazzo description's steps call into: the name its steps use for it, where
 * it is (`url`, as written), and what kind it is — null where the description does not say.
 */
final readonly class SourceDescription
{
    public function __construct(
        public string $name,
        public string $url,
        public ?SourceType $type = null,
    ) {}
}
