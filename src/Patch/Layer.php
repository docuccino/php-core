<?php

declare(strict_types=1);

namespace Docuccino\Core\Patch;

/**
 * The precedence layers (design §7). The backing integer is the precedence rank: a higher
 * value overrides a lower one. The lowercase case name is the `layer` string written into
 * provenance records and constrained by the UIR schema's `layer` enum.
 */
enum Layer: int
{
    case Fallback = 5;
    case Inference = 10;
    case Integration = 20;
    case Docblock = 30;
    case Attribute = 40;
    case Overlay = 45;
    case Config = 50;

    /**
     * The provenance `layer` string for this layer.
     */
    public function label(): string
    {
        return match ($this) {
            self::Fallback => 'fallback',
            self::Inference => 'inference',
            self::Integration => 'integration',
            self::Docblock => 'docblock',
            self::Attribute => 'attribute',
            self::Overlay => 'overlay',
            self::Config => 'config',
        };
    }
}
