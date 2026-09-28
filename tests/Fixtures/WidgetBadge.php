<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures;

/** A plain value object built through its constructor, so every key it serialises is always sent — `null` included. */
final readonly class WidgetBadge
{
    public function __construct(
        public string $id,
        public bool $pinned,
        public ?string $icon_url,
    ) {}
}
