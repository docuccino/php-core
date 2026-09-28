<?php

declare(strict_types=1);

namespace Docuccino\Core\Tests\Fixtures;

/** A plain object whose properties are initialised every way PHP allows, and one way it may leave one unset. */
final class WidgetStock
{
    public ?string $reorder_level = null;

    public ?string $note;

    public string $label;

    /** @var string|null */
    public $legacy;

    public static ?string $shared = null;

    public function __construct(
        public int $id,
        public ?string $colour = null,
        public int $quantity = 1,
    ) {
        $this->label = 'stock';
    }
}
